import os
import re
import time
import logging
import unicodedata
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
# CANALES CON MÁS DE UN PODCAST (DINÁMICO)
# ------------------------------------------------------------------
# No hay que editar nada aquí para agregar un canal nuevo: al inicio de cada
# ejecución se leen los podcasts de la BD, y todo canal que tenga 2 o más
# podcasts se detecta solo. Un video pertenece al podcast cuyo título aparece
# en el título del video (p. ej. "... | Quantum AM #3" -> podcast "Quantum AM").
# Si ninguno aparece, los videos nuevos van al podcast por defecto: el 'Activo'
# más antiguo del canal (el mismo criterio que usa el PHP).
#
# Se llena en runtime: clave = nombre o channel_id de YouTube normalizado.
PODCASTS_POR_CANAL = {}

# OPCIONAL: alias para cuando los videos no escriben el título completo del
# podcast. Clave: título del podcast (minúsculas, sin espacios). Valor: lista
# de regex extra. Normalmente se deja vacío.
#   ALIAS_PODCASTS = {"quantumam": [r"\bQAM\b"]}
ALIAS_PODCASTS = {}

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


def _clave_canal(valor):
    """Normaliza un nombre o ID de canal para compararlo (sin espacios, minúsculas)."""
    return re.sub(r"\s+", "", str(valor or "")).lower()


def _sin_acentos(texto):
    return "".join(
        c for c in unicodedata.normalize("NFKD", str(texto or ""))
        if not unicodedata.combining(c))


def _patron_podcast(nombre):
    """'Quantum AM' -> regex que acepta 'Quantum AM', 'quantum am', 'QuantumAM'."""
    tokens = [re.escape(t) for t in _sin_acentos(nombre).split()]
    return r"(?<!\w)" + r"\s*".join(tokens) + r"(?!\w)"


def construir_config_podcasts(podcasts):
    """
    A partir de la lista de podcasts de la BD arma la configuración de los
    canales que tienen 2 o más. Devuelve {clave_canal: {"default", "reglas"}}.
    """
    por_canal = {}
    for p in podcasts:
        if p.get("channel_db_id") is None or not (p.get("title") or "").strip():
            continue
        por_canal.setdefault(str(p["channel_db_id"]), []).append(p)

    config = {}
    for lista in por_canal.values():
        if len(lista) < 2:
            continue
        # Mismo criterio del PHP: primero 'Activo', luego el más antiguo
        lista.sort(key=lambda p: (str(p.get("state") or "") != "Activo",
                                  int(p.get("id") or 0)))
        reglas = []
        for p in lista:
            titulo = p["title"].strip()
            patrones = [_patron_podcast(titulo)]
            patrones += ALIAS_PODCASTS.get(_clave_canal(titulo), [])
            for patron in patrones:
                reglas.append((re.compile(patron, re.IGNORECASE), titulo))
        cfg = {"default": lista[0]["title"].strip(), "reglas": reglas}

        claves = {_clave_canal(lista[0].get("youtube_channel_id")),
                  _clave_canal(lista[0].get("channel_name"))}
        for clave in claves - {""}:
            config[clave] = cfg
    return config


def cargar_podcasts_por_canal(driver, php_endpoint):
    """Lee los podcasts de la BD y deja lista PODCASTS_POR_CANAL. No es fatal si falla."""
    res = enviar_a_php_con_session(driver, php_endpoint, {
        "token": TOKEN_SECRET,
        "action": "obtener_todos_podcasts",
    })
    if not isinstance(res, dict) or res.get("status") != "ok":
        logger.warning(
            f"⚠️ No se pudieron leer los podcasts para detectar canales "
            f"con varios podcasts: {res}")
        return

    config = construir_config_podcasts(res.get("podcasts", []))
    PODCASTS_POR_CANAL.clear()
    PODCASTS_POR_CANAL.update(config)

    for cfg in {id(c): c for c in config.values()}.values():
        nombres = [n for n in dict.fromkeys(n for _, n in cfg["reglas"])]
        logger.info(
            f"🎙️ Canal con varios podcasts detectado: {nombres} "
            f"(por defecto: {cfg['default']})")


def detectar_podcast(channel_id, channel_name, title, usar_default=True):
    """
    Devuelve el nombre del podcast según el título, o None si el canal tiene
    un solo podcast (no está en PODCASTS_POR_CANAL).
    Si el título menciona varios podcasts, gana el que aparece más al final
    (la etiqueta suele ir al final: "... | Quantum AM #3"); a igual posición,
    el de nombre más largo.
    Con usar_default=False solo devuelve un podcast si el título lo indica
    explícitamente.
    """
    cfg = (PODCASTS_POR_CANAL.get(_clave_canal(channel_id))
           or PODCASTS_POR_CANAL.get(_clave_canal(channel_name)))
    if not cfg:
        return None

    texto = _sin_acentos(title)
    mejor = None
    for patron, nombre in cfg["reglas"]:
        for m in patron.finditer(texto):
            clave = (m.end(), m.end() - m.start())
            if mejor is None or clave > mejor[0]:
                mejor = (clave, nombre)
    if mejor:
        return mejor[1]
    return cfg["default"] if usar_default else None


def revisar_y_actualizar_caratulas(driver, php_endpoint, API_KEY):
    """Consulta los podcasts en la BD, verifica si la miniatura de YouTube cambió y la actualiza si es necesario."""

    # 1. Traer todos los podcasts de la BD a través de tu endpoint PHP
    res_db = enviar_a_php_con_session(driver, php_endpoint, {
        'token': TOKEN_SECRET,
        'action': 'obtener_todos_podcasts'  # Asegúrate de tener este action en PHP
    })

    podcasts = res_db.get('podcasts', []) if isinstance(res_db, dict) else []
    if not podcasts:
        logger.info(
            "⚠️ No se encontraron podcasts en la base de datos para revisar carátulas.")
        return

    youtube = build("youtube", "v3", developerKey=API_KEY)
    logger.info("🎨 Iniciando verificación de portadas de podcasts con la BD...")

    for pod in podcasts:
        podcast_id = pod.get('id')
        # Aquí guardas el link o ID de la playlist (ej: https://www.youtube.com/playlist?list=...)
        link_playlist = pod.get('link')
        # La URL de la portada que actualmente está guardada en la BD
        imagen_actual_bd = pod.get('image')

        if not link_playlist:
            continue

        # Extraer el ID de la playlist del link
        playlist_id = link_playlist
        if "list=" in link_playlist:
            match = re.search(r'[?&]list=([a-zA-Z0-9_-]+)', link_playlist)
            if match:
                playlist_id = match.group(1)

        try:
            # Consultar la información actual a la API de YouTube
            request = youtube.playlists().list(part="snippet", id=playlist_id)
            response = request.execute()
            items = response.get("items", [])

            if items:
                thumbnails = items[0].get("snippet", {}).get("thumbnails", {})
                # Obtener la mejor calidad disponible en YouTube
                nueva_imagen_url = None
                for calidad in ["maxres", "standard", "high", "medium", "default"]:
                    if calidad in thumbnails:
                        nueva_imagen_url = thumbnails[calidad]["url"]
                        break

                # 🛡️ VALIDACIÓN: Comparar la imagen de YouTube con la que tenemos en la BD
                if nueva_imagen_url and nueva_imagen_url != imagen_actual_bd:
                    logger.info(
                        f"🔄 La portada del podcast ID {podcast_id} ha cambiado en YouTube. Actualizando...")

                    # Enviar la orden de actualización a PHP
                    res_update = enviar_a_php_con_session(driver, php_endpoint, {
                        'token': TOKEN_SECRET,
                        'action': 'actualizar_imagen_podcast',
                        'podcast_id': podcast_id,
                        'image': nueva_imagen_url
                    })

                    print(
                        f"Respuesta PHP para actualización de imagen {podcast_id}: {res_update}")
                    logger.info(
                        f"✅ Carátula actualizada con éxito para el podcast {podcast_id}")
                else:
                    # Si es idéntica, no hacemos nada (ahorramos procesos)
                    pass

        except Exception as e:
            logger.error(
                f"⚠️ Error al verificar la miniatura del podcast ID {podcast_id}: {e}")

    logger.info("🎉 Verificación de carátulas finalizada.")


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
                "podcast_name": None,   # se completa más abajo
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
                # fecha = HORA DE INICIO del directo
                info["publish_date"] = inicio

    # 3. Imagen de cada canal (1 unidad de cuota por lote de 50 canales)
    canales = sorted({v["channel_id"]
                     for v in item_map.values() if v["channel_id"]})
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
        # Podcast según el título (solo canales con más de un podcast)
        v["podcast_name"] = detectar_podcast(
            v["channel_id"], v["channel_name"], v["title"])

    return item_map

# ------------------------------------------------------------------
# SINCRONIZACIÓN CON LA BD (vía PHP)
# ------------------------------------------------------------------


def corregir_podcasts(driver, php_endpoint, db_map, stats):
    """
    Reasigna al podcast correcto los episodios cuyo título lo indica, estén o
    no en la playlist (incluye los ya PUBLISHED). Va en orden cronológico para
    que la numeración en el podcast destino quede ordenada.
    """
    episodios = sorted(db_map.values(),
                       key=lambda e: str(e.get("publish_date") or ""))
    for ep in episodios:
        esperado = detectar_podcast(
            (ep.get("channel_youtube_id") or "").strip(),
            (ep.get("channel_name") or "").strip(),
            ep.get("title"),
            usar_default=False,   # solo mueve si el título lo dice explícitamente
        )
        actual = (ep.get("podcast_title") or "").strip().lower()
        if not esperado or esperado.strip().lower() == actual:
            continue

        res = enviar_a_php_con_session(driver, php_endpoint, {
            "token": TOKEN_SECRET,
            "action": "actualizar_podcast",
            "youtube_id": str(ep["youtube_id"]).strip(),
            "podcast_name": esperado,
        })
        if res.get("status") == "success":
            logger.info(
                f"  🎙️ {ep.get('podcast_title')} -> {esperado}: {ep.get('title')}")
            stats["actualizados"] += 1
        else:
            stats["errores"] += 1
            logger.error(
                f"  ❌ Error moviendo podcast de {ep['youtube_id']}: {res}")


def sincronizar_playlist_global(driver, php_endpoint, playlist_ids, stats):
    logger.info("📋 Sincronizando playlist designada...")

    if not playlist_ids:
        logger.warning(
            "⚠️ Playlist vacía. Se omite para evitar falsos PUBLISHED.")
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
                "podcast_name": v.get("podcast_name") or "",
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
            logger.info(
                f"  ⏱️ Duración actualizada: {v['title']} ({v['duration']})")
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
                    logger.error(
                        f"  ❌ Error actualizando fecha de {yt_id}: {res}")

    # 2. Los que salieron de la playlist -> PUBLISHED
    for yt_id, ep in db_map.items():
        if yt_id not in playlist_ids and ep.get("status") != "PUBLISHED":
            logger.info(
                f"  ✅ Salió de la playlist (PUBLISHED): {ep.get('title', yt_id)}")
            enviar_a_php_con_session(driver, php_endpoint, {
                "token": TOKEN_SECRET,
                "action": "actualizar_estado",
                "youtube_id": yt_id,
                "status": "PUBLISHED",
            })
            stats["actualizados"] += 1

    # 3. Corregir podcast de episodios (nuevos y antiguos) según su título
    corregir_podcasts(driver, php_endpoint, db_map, stats)

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

        # Detecta canales con varios podcasts leyendo la BD (sin config manual)
        cargar_podcasts_por_canal(driver, PHP_ENDPOINT)

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

        revisar_y_actualizar_caratulas(driver, PHP_ENDPOINT, API_KEY)

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
