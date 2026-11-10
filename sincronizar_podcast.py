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
        "episodios=%s | en_vivo=%s | actualizados=%s | errores=%s%s",
        estado,
        inicio.strftime("%Y-%m-%d %H:%M:%S"),
        fin.strftime("%Y-%m-%d %H:%M:%S"),
        duracion,
        stats.get("episodios", 0),
        stats.get("en_vivo", 0),
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


def estado_directo(video):
    """
    Estado de transmisión de un video según YouTube:
      'live'     -> transmitiendo ahora
      'upcoming' -> directo programado que aún no empieza
      'none'     -> video normal o directo ya terminado
    """
    lb = (video.get("snippet", {}) or {}).get("liveBroadcastContent", "none")
    if lb in ("live", "upcoming"):
        return lb
    # Respaldo: tiene hora de inicio real, pero no de término -> sigue en vivo
    live = video.get("liveStreamingDetails", {}) or {}
    if live.get("actualStartTime") and not live.get("actualEndTime"):
        return "live"
    return "none"


def fecha_publicacion_real(video):
    """
    Fecha que debe tener un episodio, en hora de Chile ('YYYY-MM-DD HH:MM:SS'):
      - Directo (en curso o terminado): HORA DE INICIO del directo (actualStartTime).
      - Directo programado que aún no empieza: la hora programada (scheduledStartTime).
      - Video normal: su fecha de publicación (snippet.publishedAt).
    OJO: playlistItems.publishedAt NO sirve: es la fecha en que se agregó a la playlist.
    """
    live = video.get("liveStreamingDetails", {}) or {}
    snip = video.get("snippet", {}) or {}
    if live.get("actualStartTime"):
        return utc_a_chile(live["actualStartTime"])
    if live.get("scheduledStartTime") and snip.get("liveBroadcastContent") == "upcoming":
        return utc_a_chile(live["scheduledStartTime"])
    return utc_a_chile(snip.get("publishedAt"))


def verificar_ultimos_episodios(driver, php_endpoint, api_key, stats, cantidad=3):
    """
    Revisa los últimos `cantidad` episodios de CADA podcast contra YouTube:
      - si el título cambió, lo actualiza;
      - si la fecha de publicación no es la correcta, la corrige
        (en los directos, la fecha es la hora de inicio del en vivo).
    Cuesta 1 unidad de cuota por cada 50 videos.
    """
    res = enviar_a_php_con_session(driver, php_endpoint, {
        "token": TOKEN_SECRET,
        "action": "obtener_ultimos_episodios",
        "cantidad": cantidad,
    })
    if not isinstance(res, dict) or res.get("status") != "success":
        logger.warning(f"⚠️ No se pudieron leer los últimos episodios: {res}")
        return

    episodios = [e for e in res.get("episodios", []) if e.get("youtube_id")]
    if not episodios:
        return
    logger.info(
        f"🔎 Verificando los últimos {cantidad} episodios de cada podcast "
        f"({len(episodios)} videos)...")

    youtube = build("youtube", "v3", developerKey=api_key)
    ids = list(dict.fromkeys(str(e["youtube_id"]).strip() for e in episodios))
    detalles = {}
    for i in range(0, len(ids), 50):
        resp = youtube.videos().list(
            part="snippet,liveStreamingDetails",
            id=",".join(ids[i:i + 50]),
        ).execute()
        for v in resp.get("items", []):
            detalles[v["id"]] = v

    for ep in episodios:
        yt_id = str(ep["youtube_id"]).strip()
        v = detalles.get(yt_id)
        if v is None:
            # Privado, eliminado o no disponible: no se toca nada
            logger.warning(f"  ⚠️ Video no disponible en YouTube, se omite: {ep.get('title')}")
            continue
        stats["verificados"] += 1

        # 1. ¿Cambió el título?
        titulo_yt = (v.get("snippet", {}).get("title") or "").strip()
        titulo_bd = (ep.get("title") or "").strip()
        if titulo_yt and titulo_yt != titulo_bd:
            r = enviar_a_php_con_session(driver, php_endpoint, {
                "token": TOKEN_SECRET,
                "action": "actualizar_titulo",
                "youtube_id": yt_id,
                "title": titulo_yt,
            })
            if r.get("status") == "success":
                logger.info(f"  ✏️ Título cambió en YouTube: «{titulo_bd}» → «{titulo_yt}»")
                stats["corregidos"] += 1
                stats["actualizados"] += 1
            else:
                stats["errores"] += 1
                logger.error(f"  ❌ Error actualizando título de {yt_id}: {r}")

        # 2. ¿La fecha es la correcta?
        fecha_ok = fecha_publicacion_real(v)
        fecha_bd = str(ep.get("publish_date") or "")[:19]
        if fecha_ok and fecha_ok != fecha_bd:
            r = enviar_a_php_con_session(driver, php_endpoint, {
                "token": TOKEN_SECRET,
                "action": "actualizar_fecha",
                "youtube_id": yt_id,
                "publish_date": fecha_ok,
            })
            es_live = bool((v.get("liveStreamingDetails") or {}).get("actualStartTime"))
            if r.get("status") == "success":
                motivo = "inicio del directo" if es_live else "publicación del video"
                logger.info(
                    f"  📅 Fecha corregida ({motivo}): {fecha_bd or 'vacía'} → {fecha_ok} · {titulo_yt or titulo_bd}")
                stats["corregidos"] += 1
                stats["actualizados"] += 1
            else:
                stats["errores"] += 1
                logger.error(f"  ❌ Error corrigiendo fecha de {yt_id}: {r}")


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
                "live_status": "none",      # none | upcoming | live
                "podcast_name": None,   # se completa más abajo
            }

        page_token = resp.get("nextPageToken")
        if not page_token:
            break

    # 2. Detalles en lote (duración + datos de directo).
    #    'snippet' trae liveBroadcastContent (live/upcoming/none); no cuesta cuota extra.
    ids = list(item_map.keys())
    for i in range(0, len(ids), 50):
        resp = youtube.videos().list(
            part="snippet,contentDetails,liveStreamingDetails",
            id=",".join(ids[i:i + 50]),
        ).execute()

        for v in resp.get("items", []):
            info = item_map.get(v["id"])
            if not info:
                continue

            info["duration"] = parsear_duracion_iso(
                v.get("contentDetails", {}).get("duration", "PT0S"))

            # ¿Está en vivo / programado ahora?
            info["live_status"] = estado_directo(v)

            # Fecha REAL (inicio del directo o publicación del video). La de la playlist
            # es la fecha en que se agregó a la lista, no la del video.
            real = fecha_publicacion_real(v)
            if real:
                info["publish_date"] = real

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


def notificar_directos(directos):
    """Avisa por log y Telegram cada directo que EMPEZÓ desde la última ejecución."""
    for v in directos:
        podcast = v.get("podcast_name") or v.get("channel_name") or "Canal"
        logger.info(f"  🔴 EN VIVO ahora: {podcast} — {v['title']}")
        enviar_mensaje_telegram(
            f"🔴 EN VIVO ahora\n{podcast}\n{v['title']}\n"
            f"https://www.youtube.com/watch?v={v['youtube_id']}")


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

    nuevos_en_vivo = []   # directos que empezaron desde la última ejecución

    # 1. Videos de la playlist
    for yt_id, v in playlist_ids.items():
        ep = db_map.get(yt_id)

        # 0. ¿Está en vivo ahora? (cuenta y detecta el inicio del directo)
        if v.get("live_status") == "live":
            stats["en_vivo"] += 1
            if ep is None or (ep.get("live_status") or "none") != "live":
                nuevos_en_vivo.append(v)

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
                "live_status": v.get("live_status", "none"),
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

        # 1e. Estado de directo (en vivo / programado / ninguno)
        #     Solo si la BD ya tiene la columna live_status (migracion.sql)
        if "live_status" in ep:
            antes = ep.get("live_status") or "none"
            ahora = v.get("live_status", "none")
            if ahora != antes:
                res = enviar_a_php_con_session(driver, php_endpoint, {
                    "token": TOKEN_SECRET,
                    "action": "actualizar_live_status",
                    "youtube_id": yt_id,
                    "live_status": ahora,
                })
                if res.get("status") == "success":
                    if antes == "live" and ahora == "none":
                        logger.info(f"  ⏹️ Directo finalizado: {v['title']}")
                    else:
                        logger.info(
                            f"  📡 Estado de directo {antes} -> {ahora}: {v['title']}")
                    stats["actualizados"] += 1
                else:
                    stats["errores"] += 1
                    logger.error(
                        f"  ❌ Error actualizando live_status de {yt_id}: {res}")

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

    # 2b. Limpieza: un episodio que ya no está en la playlist no puede seguir "en vivo"
    #     (evita que quede un directo fantasma si salió de la playlist antes de terminar)
    for yt_id, ep in db_map.items():
        if yt_id not in playlist_ids and (ep.get("live_status") or "none") != "none":
            res = enviar_a_php_con_session(driver, php_endpoint, {
                "token": TOKEN_SECRET,
                "action": "actualizar_live_status",
                "youtube_id": yt_id,
                "live_status": "none",
            })
            if res.get("status") == "success":
                logger.info(
                    f"  🧹 Directo fuera de la playlist, se quita 'en vivo': {ep.get('title', yt_id)}")
                stats["actualizados"] += 1

    # 3. Corregir podcast de episodios (nuevos y antiguos) según su título
    corregir_podcasts(driver, php_endpoint, db_map, stats)

    # 4. Avisar de los directos que empezaron
    if nuevos_en_vivo:
        notificar_directos(nuevos_en_vivo)

# ------------------------------------------------------------------
# PROCESO PRINCIPAL
# ------------------------------------------------------------------


def procesar():
    inicio = datetime.now(TZ_CHILE)
    stats = {"episodios": 0, "en_vivo": 0, "actualizados": 0, "errores": 0,
             "verificados": 0, "corregidos": 0}
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

        # Primero se verifican los últimos episodios de cada podcast (título y fecha),
        # así la sincronización y la reasignación de podcast trabajan con datos al día.
        try:
            verificar_ultimos_episodios(driver, PHP_ENDPOINT, API_KEY, stats)
        except Exception as e:
            logger.error(f"⚠️ Falló la verificación de últimos episodios: {e}")

        sincronizar_playlist_global(driver, PHP_ENDPOINT, playlist_ids, stats)

        enviar_a_php_con_session(driver, PHP_ENDPOINT, {
            "token": TOKEN_SECRET,
            "action": "registrar_fin_sincronizacion",
        })

        enviar_mensaje_telegram(
            "✅ Sincronización finalizada.\n"
            f"- Videos en playlist: {stats['episodios']}\n"
            f"- En vivo ahora: {stats['en_vivo']}\n"
            f"- Últimos episodios verificados: {stats['verificados']} "
            f"(corregidos: {stats['corregidos']})\n"
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