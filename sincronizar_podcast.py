import os
import re
import time
import logging
from datetime import datetime
from zoneinfo import ZoneInfo

import requests
from dotenv import load_dotenv
from googleapiclient.discovery import build

from navegador import configurar_navegador

load_dotenv()

API_KEY = os.getenv("API_KEY")
DOWNLOAD_DIR = os.getenv("DOWNLOAD_DIR")
PHP_ENDPOINT = os.getenv("PHP_ENDPOINT")
HISTORIAL_FILE = os.getenv("HISTORIAL_FILE", "historial_ejecuciones.txt")
TOKEN_SECRET = os.getenv("TOKEN_SECRET", "MI_CLAVE_SUPER_SECRETA_123")
PLAYLIST_URL = os.getenv(
    "PLAYLIST_URL",
    "https://www.youtube.com/playlist?list=PL4wOdIekMghnJXO-_gOclIXiiZyPhhsxQ",
)
TZ_CHILE = ZoneInfo("America/Santiago")

# ------------------------------------------------------------------
# LOGGING
# ------------------------------------------------------------------
logger = logging.getLogger("sincronizar_podcast")


def configurar_logging():
    if logger.handlers:
        return
    logger.setLevel(logging.INFO)
    formato = logging.Formatter(
        "%(asctime)s [%(levelname)s] %(message)s", "%Y-%m-%d %H:%M:%S")
    h_archivo = logging.FileHandler(HISTORIAL_FILE, mode="a", encoding="utf-8")
    h_archivo.setFormatter(formato)
    logger.addHandler(h_archivo)
    h_consola = logging.StreamHandler()
    h_consola.setFormatter(formato)
    logger.addHandler(h_consola)


configurar_logging()

# ------------------------------------------------------------------
# UTILIDADES
# ------------------------------------------------------------------


def guardar_historial_ejecucion(inicio, fin, estado, stats, mensaje=""):
    duracion = int((fin - inicio).total_seconds())
    nivel = logging.INFO if estado == "OK" else logging.ERROR
    logger.log(
        nivel,
        "RESUMEN | estado=%s | inicio=%s | fin=%s | duracion=%ss | "
        "episodios=%s | actualizados=%s | errores=%s%s",
        estado,
        inicio.strftime("%Y-%m-%d %H:%M:%S"),
        fin.strftime("%Y-%m-%d %H:%M:%S"),
        duracion,
        stats.get("episodios", 0),
        stats.get("actualizados", 0),
        stats.get("errores", 0),
        f" | mensaje={mensaje}" if mensaje else "",
    )
    logger.info("=" * 70)


def utc_a_chile(raw):
    """'2025-01-31T22:00:00Z' (UTC) -> 'YYYY-MM-DD HH:MM:SS' (America/Santiago)."""
    if not raw:
        return None
    try:
        # Algunas fechas traen milisegundos (…:00.123Z); se descartan.
        raw = raw.split(".")[0].rstrip("Z") + "Z"
        utc_dt = datetime.strptime(
            raw, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=ZoneInfo("UTC"))
        return utc_dt.astimezone(TZ_CHILE).strftime("%Y-%m-%d %H:%M:%S")
    except Exception:
        return None


def parsear_duracion_iso(iso_duration):
    """PT1H2M10S -> 01:02:10"""
    m = re.match(r"PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?", iso_duration or "")
    if not m:
        return "00:00:00"
    h, mi, s = (int(g) if g else 0 for g in m.groups())
    return f"{h:02d}:{mi:02d}:{s:02d}"


def enviar_a_php_con_session(driver, php_endpoint, payload):
    """POST a PHP vía fetch() dentro de Selenium (evita bloqueo de InfinityFree)."""
    if php_endpoint not in driver.current_url:
        driver.get(php_endpoint)

    script = """
    const callback = arguments[arguments.length - 1];
    const payloadData = arguments[0];
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payloadData)
    })
    .then(r => r.text())
    .then(text => {
        try { callback(JSON.parse(text)); }
        catch(e) { callback({status: 'error', message: 'HTML_RETURNED', raw: text}); }
    })
    .catch(err => callback({status: 'error', message: err.toString()}));
    """
    try:
        res = driver.execute_async_script(script, payload)
        if isinstance(res, dict) and res.get("message") == "HTML_RETURNED":
            logger.warning(
                f"⚠️ [RESPUESTA RAW DE INFINITYFREE]:\n{res.get('raw', '')[:400]}\n")
            return {"status": "error", "message": "Servidor devolvió HTML/Error de PHP"}
        return res
    except Exception as e:
        return {"status": "error", "message": str(e)}


def enviar_mensaje_telegram(mensaje):
    token = os.getenv("TELEGRAM_BOT_TOKEN")
    chat_id = os.getenv("TELEGRAM_CHAT_ID")
    if not token or not chat_id:
        return False
    url = f"https://api.telegram.org/bot{token}/sendMessage"
    payload = {"chat_id": chat_id, "text": mensaje}
    for intento in range(1, 4):
        try:
            r = requests.post(url, json=payload, timeout=10)
            if r.status_code == 200:
                logger.info("Mensaje de Telegram enviado con éxito.")
                return True
            logger.error(f"Intento {intento}/3 - Telegram: {r.text}")
        except Exception as e:
            logger.error(f"Intento {intento}/3 - Excepción Telegram: {e}")
        if intento < 3:
            time.sleep(3)
    return False

# ------------------------------------------------------------------
# YOUTUBE: SOLO LA PLAYLIST DESIGNADA + SUS DETALLES
# ------------------------------------------------------------------


def obtener_videos_playlist_api(url_or_playlist_id, api_key):
    """
    Devuelve {youtube_id: info} con TODOS los videos de la playlist (con paginación).
    Si la API falla, LANZA la excepción: así nunca se sincroniza con una lista
    incompleta (que marcaría episodios como PUBLISHED por error).
    """
    youtube = build("youtube", "v3", developerKey=api_key)

    playlist_id = url_or_playlist_id
    m = re.search(r"[?&]list=([a-zA-Z0-9_-]+)", url_or_playlist_id)
    if m:
        playlist_id = m.group(1)

    # 1. Items de la playlist (paginado)
    item_map = {}
    page_token = None
    while True:
        resp = youtube.playlistItems().list(
            part="snippet",
            playlistId=playlist_id,
            maxResults=50,
            pageToken=page_token,
        ).execute()

        for item in resp.get("items", []):
            sn = item.get("snippet", {})
            if sn.get("title") in ("Private video", "Deleted video"):
                continue
            vid_id = sn.get("resourceId", {}).get("videoId")
            if not vid_id:
                continue

            item_map[vid_id] = {
                "youtube_id": vid_id,
                "title": sn.get("title", "Sin título").strip(),
                "channel_id": (sn.get("videoOwnerChannelId") or sn.get("channelId", "")).strip(),
                "channel_name": (sn.get("videoOwnerChannelTitle") or sn.get("channelTitle") or "Desconocido").strip(),
                "publish_date": (utc_a_chile(sn.get("publishedAt"))
                                 or datetime.now(TZ_CHILE).strftime("%Y-%m-%d %H:%M:%S")),
                "duration": "00:00:00",
                "actual_start_time": None,
                "es_directo_finalizado": False,
            }

        page_token = resp.get("nextPageToken")
        if not page_token:
            break

    # 2. Detalles en lote (duración + datos de directo)
    ids = list(item_map.keys())
    for i in range(0, len(ids), 50):
        resp = youtube.videos().list(
            part="contentDetails,liveStreamingDetails",
            id=",".join(ids[i:i + 50]),
        ).execute()

        for v in resp.get("items", []):
            info = item_map.get(v["id"])
            if not info:
                continue

            info["duration"] = parsear_duracion_iso(
                v.get("contentDetails", {}).get("duration", "PT0S"))

            live = v.get("liveStreamingDetails", {})
            inicio = utc_a_chile(live.get("actualStartTime"))
            # Directo FINALIZADO = tiene hora real de inicio Y de término
            if inicio and live.get("actualEndTime"):
                info["actual_start_time"] = inicio
                info["es_directo_finalizado"] = True
                info["publish_date"] = inicio   # fecha = HORA DE INICIO del directo

    # 3. Imagen de cada canal (1 unidad de cuota por lote de 50 canales)
    canales = sorted({v["channel_id"] for v in item_map.values() if v["channel_id"]})
    imagenes = {}
    for i in range(0, len(canales), 50):
        resp = youtube.channels().list(
            part="snippet", id=",".join(canales[i:i + 50])).execute()
        for c in resp.get("items", []):
            th = c.get("snippet", {}).get("thumbnails", {})
            img = th.get("high") or th.get("medium") or th.get("default") or {}
            imagenes[c["id"]] = img.get("url", "")
    for v in item_map.values():
        v["channel_image"] = imagenes.get(v["channel_id"], "")

    return item_map

# ------------------------------------------------------------------
# SINCRONIZACIÓN CON LA BD (vía PHP)
# ------------------------------------------------------------------


def sincronizar_playlist_global(driver, php_endpoint, playlist_ids, stats):
    logger.info("📋 Sincronizando playlist designada...")

    if not playlist_ids:
        logger.warning("⚠️ Playlist vacía. Se omite para evitar falsos PUBLISHED.")
        return

    res_db = enviar_a_php_con_session(driver, php_endpoint, {
        "token": TOKEN_SECRET,
        "action": "obtener_todos_episodios",
    })
    if not isinstance(res_db, dict) or res_db.get("status") != "success":
        raise RuntimeError(f"No se pudo leer episodios de la BD: {res_db}")

    db_map = {
        str(ep["youtube_id"]).strip(): ep
        for ep in res_db.get("episodios", [])
        if ep and ep.get("youtube_id") is not None
    }

    # 1. Videos de la playlist
    for yt_id, v in playlist_ids.items():
        ep = db_map.get(yt_id)

        # 1a. Nuevo
        if ep is None:
            logger.info(f"  🆕 Nuevo en playlist: {v['title']}")
            res = enviar_a_php_con_session(driver, php_endpoint, {
                "token": TOKEN_SECRET,
                "action": "guardar_episodio",
                "youtube_id": yt_id,
                "channel_id": v.get("channel_id", ""),
                "channel_name": v.get("channel_name", ""),
                "channel_image": v.get("channel_image", ""),
                "title": v["title"],
                "duration": v["duration"],
                "status": "PENDING",
                "publish_date": v["publish_date"],
            })
            if res.get("status") in ("inserted", "updated"):
                stats["actualizados"] += 1
            else:
                stats["errores"] += 1
                logger.error(f"  ❌ Error guardando {yt_id}: {res}")
            continue

        # 1b. Reactivar si volvió a la playlist
        if ep.get("status") == "PUBLISHED":
            logger.info(f"  🔄 Reactivando a PENDING: {v['title']}")
            enviar_a_php_con_session(driver, php_endpoint, {
                "token": TOKEN_SECRET,
                "action": "actualizar_estado",
                "youtube_id": yt_id,
                "status": "PENDING",
            })
            stats["actualizados"] += 1

        # 1c. Duración (si estaba en 0 y ahora ya existe)
        if (ep.get("duration") in (None, "", "00:00:00")) and v["duration"] != "00:00:00":
            logger.info(f"  ⏱️ Duración actualizada: {v['title']} ({v['duration']})")
            enviar_a_php_con_session(driver, php_endpoint, {
                "token": TOKEN_SECRET,
                "action": "actualizar_duracion",
                "youtube_id": yt_id,
                "duration": v["duration"],
            })
            stats["actualizados"] += 1

        # 1d. Directo terminado -> fecha = hora de inicio del directo
        if v["es_directo_finalizado"]:
            fecha_bd = str(ep.get("publish_date") or "")[:19]
            if fecha_bd != v["actual_start_time"]:
                res = enviar_a_php_con_session(driver, php_endpoint, {
                    "token": TOKEN_SECRET,
                    "action": "actualizar_fecha",
                    "youtube_id": yt_id,
                    "publish_date": v["actual_start_time"],
                })
                if res.get("status") == "success":
                    logger.info(
                        f"  📅 Directo finalizado, fecha -> inicio del live "
                        f"({fecha_bd or 'vacía'} → {v['actual_start_time']}): {v['title']}")
                    stats["actualizados"] += 1
                else:
                    stats["errores"] += 1
                    logger.error(f"  ❌ Error actualizando fecha de {yt_id}: {res}")

    # 2. Los que salieron de la playlist -> PUBLISHED
    for yt_id, ep in db_map.items():
        if yt_id not in playlist_ids and ep.get("status") != "PUBLISHED":
            logger.info(f"  ✅ Salió de la playlist (PUBLISHED): {ep.get('title', yt_id)}")
            enviar_a_php_con_session(driver, php_endpoint, {
                "token": TOKEN_SECRET,
                "action": "actualizar_estado",
                "youtube_id": yt_id,
                "status": "PUBLISHED",
            })
            stats["actualizados"] += 1

# ------------------------------------------------------------------
# PROCESO PRINCIPAL
# ------------------------------------------------------------------


def procesar():
    inicio = datetime.now(TZ_CHILE)
    stats = {"episodios": 0, "actualizados": 0, "errores": 0}
    estado, mensaje = "OK", ""
    driver = None

    logger.info("=" * 70)
    logger.info("INICIO DE EJECUCIÓN")

    try:
        logger.info("🌐 Iniciando navegador Selenium...")
        driver = configurar_navegador(DOWNLOAD_DIR)

        driver.get("https://www.google.com")
        time.sleep(3)
        logger.info("🔑 Pasando validación de seguridad de InfinityFree...")
        driver.get(PHP_ENDPOINT)
        time.sleep(3)

        playlist_ids = obtener_videos_playlist_api(PLAYLIST_URL, API_KEY)
        stats["episodios"] = len(playlist_ids)
        logger.info(f"  ✅ {len(playlist_ids)} videos en la playlist.")

        sincronizar_playlist_global(driver, PHP_ENDPOINT, playlist_ids, stats)

        enviar_a_php_con_session(driver, PHP_ENDPOINT, {
            "token": TOKEN_SECRET,
            "action": "registrar_fin_sincronizacion",
        })

        enviar_mensaje_telegram(
            "✅ Sincronización finalizada.\n"
            f"- Videos en playlist: {stats['episodios']}\n"
            f"- Cambios aplicados: {stats['actualizados']}\n"
            f"- Errores: {stats['errores']}")

    except Exception as e:
        estado = "ERROR"
        mensaje = f"{type(e).__name__}: {e}"
        logger.exception("Error no controlado durante la ejecución")
        enviar_mensaje_telegram(f"❌ Error en la sincronización: {mensaje}")
        raise

    finally:
        if driver:
            logger.info("🔒 Cerrando navegador Selenium...")
            driver.quit()
        fin = datetime.now(TZ_CHILE)
        guardar_historial_ejecucion(inicio, fin, estado, stats, mensaje)


if __name__ == "__main__":
    procesar()