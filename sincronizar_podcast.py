import time
import csv
import json
import requests  # Importante para enviar a PHP sin bloqueos CORS
from datetime import datetime
import isodate
from zoneinfo import ZoneInfo  # Requiere Python 3.9+
import yt_dlp  # Necesario para leer playlists sin descargar

from navegador import configurar_navegador

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from webdriver_manager.chrome import ChromeDriverManager

import os
from dotenv import load_dotenv

# Cargar las variables
load_dotenv()

# Obtener el link o ruta del CSV
CSV_URL = os.getenv("CSV_URL")
API_KEY = os.getenv("API_KEY")
DOWNLOAD_DIR = os.getenv("DOWNLOAD_DIR")

# URL de tu script PHP subido a InfinityFree
PHP_ENDPOINT = "http://localhost/Podcast_Manager/api_insertar.php"
# PHP_ENDPOINT = "https://inventarioncc.infinityfreeapp.com/Podcast%20Manager/api_insertar.php"
TOKEN_SECRET = "MI_CLAVE_SUPER_SECRETA_123"

# Coloca aquí la URL de tu playlist de YouTube o la fuente de pendientes
PLAYLIST_URL = "https://www.youtube.com/playlist?list=PL4wOdIekMghnJXO-_gOclIXiiZyPhhsxQ"

# ------------------------------------------------------------------
# FUNCIONES AUXILIARES
# ------------------------------------------------------------------


def duracion_a_segundos(iso_duration):
    try:
        dur = isodate.parse_duration(iso_duration)
        return int(dur.total_seconds())
    except:
        return 0


def iso8601_a_hhmmss(iso_duration):
    segundos = duracion_a_segundos(iso_duration)
    m, s = divmod(segundos, 60)
    h, m = divmod(m, 60)
    return f"{h:02d}:{m:02d}:{s:02d}"


def cumple_filtros(titulo, permitidas_str, bloqueadas_str):
    titulo_lower = titulo.lower()

    if bloqueadas_str:
        bloqueadas = [p.strip().lower()
                      for p in bloqueadas_str.split(',') if p.strip()]
        for p in bloqueadas:
            if p in titulo_lower:
                return False

    if permitidas_str:
        permitidas = [p.strip().lower()
                      for p in permitidas_str.split(',') if p.strip()]
        encontrada = any(p in titulo_lower for p in permitidas)
        if not encontrada:
            return False

    return True


def obtener_json_con_selenium(driver, url):
    """Descarga datos de YouTube o Google Sheets usando Selenium."""
    js_code = f"""
        var done = arguments[arguments.length - 1];
        fetch("{url}")
            .then(response => response.text())
            .then(data => done(data))
            .catch(err => done(JSON.stringify({{error: err.toString()}})));
    """
    return driver.execute_async_script(js_code)


def enviar_a_php_con_session(driver, php_endpoint, payload):
    """Envía datos por POST usando JavaScript (fetch) directamente dentro

    del navegador Selenium, evitando cualquier bloqueo de InfinityFree.
    """
    if php_endpoint not in driver.current_url:
        driver.get(php_endpoint)

    script = """
    const callback = arguments[arguments.length - 1];
    const payloadData = arguments[0];

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payloadData)
    })
    .then(response => response.text())
    .then(text => {
        try {
            callback(JSON.parse(text));
        } catch(e) {
            callback({status: 'error', message: 'HTML_RETURNED', raw: text});
        }
    })
    .catch(error => callback({status: 'error', message: error.toString()}));
    """

    try:
        res_json = driver.execute_async_script(script, payload)
        if isinstance(res_json, dict) and res_json.get("message") == "HTML_RETURNED":
            print(
                f"\n⚠️️ [RESPUESTA RAW DE INFINITYFREE]:\n{res_json.get('raw')[:400]}\n")
            return {"status": "error", "message": "Servidor devolvió HTML/Error de PHP"}
        return res_json
    except Exception as e:
        return {"status": "error", "message": str(e)}

def obtener_videos_playlist(url_playlist):
    """Extrae de forma rápida los IDs, títulos, duración y el canal de origen usando yt-dlp."""
    ydl_opts = {
        'extract_flat': True,
        'skip_download': True,
        'quiet': True
    }
    
    videos = []
    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
        try:
            resultado = ydl.extract_info(url_playlist, download=False)
            if resultado and 'entries' in resultado:
                for entry in resultado['entries']:
                    if entry:
                        vid_id = entry.get('id')
                        if vid_id:
                            vid_title = entry.get('title') or 'Sin título'
                            
                            # Capturamos el identificador del canal del video en la playlist
                            channel_id = entry.get('channel_id') or entry.get('uploader_id') or ''
                            channel_name = entry.get('channel') or entry.get('uploader') or 'Desconocido'
                            
                            # Duración
                            duracion_segundos = entry.get('duration')
                            if duracion_segundos and isinstance(duracion_segundos, (int, float)) and duracion_segundos > 0:
                                horas = int(duracion_segundos // 3600)
                                minutos = int((duracion_segundos % 3600) // 60)
                                segundos = int(duracion_segundos % 60)
                                duracion_str = f"{horas:02d}:{minutos:02d}:{segundos:02d}"
                            else:
                                duracion_str = "00:00:00"
                            
                            videos.append({
                                "youtube_id": str(vid_id).strip(),
                                "title": str(vid_title).strip(),
                                "duration": duracion_str,
                                "channel_id": str(channel_id).strip(),
                                "channel_name": str(channel_name).strip()
                            })
        except Exception as e:
            print(f"Error al extraer la playlist general: {e}")
            
    return videos


def sincronizar_playlist_pendientes(driver, php_endpoint, podcast_id, playlist_ids):
    """Sincroniza la playlist global ya extraída con la BD del podcast actual:

    - Si está en la playlist general: se asegura de crearlo o dejarlo como PENDING.
    - Si estaba en la BD pero ya NO está en la playlist general: lo marca como PUBLISHED.
    """
    print(f"\n📋 Sincronizando playlist de pendientes...")

    # 🛡️ VALIDACIÓN DE SEGURIDAD CRÍTICA
    if len(playlist_ids) == 0:
        print("⚠️ ADVERTENCIA: La playlist está vacía. Omitiendo limpieza para evitar falsos positivos.")
        return  # Sale de la función de forma segura sin alterar la base de datos

    # 2. Consultar a la BD qué episodios existen actualmente para este podcast
    res_db = enviar_a_php_con_session(driver, php_endpoint, {
        'token': TOKEN_SECRET,
        'action': 'obtener_episodios_podcast',
        'podcast_id': podcast_id
    })

    episodios_db = res_db.get(
        'episodios', []) if isinstance(res_db, dict) else []

    # 🛡️ Blindaje: Aseguramos que solo procese registros con un youtube_id válido
    db_map = {
        str(ep['youtube_id']).strip(): ep
        for ep in episodios_db
        if ep and ep.get('youtube_id') is not None
    }

    # 3. Caso A & B: Si están en YouTube (crear o mantener PENDING)
    for yt_id, v_info in playlist_ids.items():
        if yt_id not in db_map:
            print(
                f"  🆕 Nuevo en playlist (Creando PENDING): {v_info['title']}")
            enviar_a_php_con_session(driver, php_endpoint, {
                'token': TOKEN_SECRET,
                'action': 'guardar_episodio',
                'youtube_id': yt_id,
                'podcast_id': podcast_id,
                'title': v_info['title'],
                'duration': v_info['duration'],  # <--- Aquí toma "00:00:00" si es en vivo o la duración real si la tiene
                'status': 'PENDING',
                'publish_date': datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            })
        else:
            # Opcional: Si ya existe pero su duración estaba en ceros y ahora YouTube ya la conoce, la actualizamos
            duracion_actual_db = db_map[yt_id].get('duration', '00:00:00')
            # Si ya estaba pero estaba completado y volvió a la playlist, lo pasamos a pendiente
            if db_map[yt_id].get('status') == 'PUBLISHED':
                print(f"  🔄 Reactivando a PENDING (volvió a la playlist): {v_info['title']}")
                enviar_a_php_con_session(driver, php_endpoint, {
                    'token': TOKEN_SECRET,
                    'action': 'actualizar_estado',
                    'youtube_id': yt_id,
                    'status': 'PENDING'
                })
            elif duracion_actual_db == "00:00:00" and v_info['duration'] != "00:00:00":
                # Si antes estaba en ceros (ej. era directo) y ahora ya tiene duración, la actualizamos en PHP
                print(f"  ⏱️ Actualizando duración para: {v_info['title']} ({v_info['duration']})")
                enviar_a_php_con_session(driver, php_endpoint, {
                    'token': TOKEN_SECRET,
                    'action': 'actualizar_duracion', # Asegúrate de tener esta acción en tu PHP si deseas actualizarla al vuelo
                    'youtube_id': yt_id,
                    'duration': v_info['duration']
                })

    # 4. Caso C: Está en la BD pero YA NO está en YouTube -> Marcar como PUBLISHED
    for yt_id, ep_info in db_map.items():
        # Quitamos la restricción estricta de != 'PUBLISHED' si quieres asegurar que refresque la fecha,
        # o nos aseguramos de que compare contra el estado actual de la BD
        if yt_id not in playlist_ids and ep_info.get('status') != 'PUBLISHED':
            print(f"  ✅ Quitado de YouTube (Marcando como PUBLISHED): {ep_info.get('title', yt_id)}")
            enviar_a_php_con_session(driver, php_endpoint, {
                'token': TOKEN_SECRET,
                'action': 'actualizar_estado',
                'youtube_id': yt_id,
                'status': 'PUBLISHED'
            })

# ------------------------------------------------------------------
# PROCESO PRINCIPAL
# ------------------------------------------------------------------


def procesar():
    print("🌐 Iniciando navegador Selenium...")
    driver = configurar_navegador(DOWNLOAD_DIR)

    try:
        driver.get("https://www.google.com")
        time.sleep(3)

        print("📄 Descargando configuración desde Google Sheets CSV...")
        raw_csv = obtener_json_con_selenium(driver, CSV_URL)

        lines = raw_csv.splitlines()
        reader = csv.reader(lines)
        header = next(reader, None)

        print("🔑 Pasando validación de seguridad de InfinityFree...")
        driver.get(PHP_ENDPOINT)
        time.sleep(3)


        # =========================================================================
        # 1. BARRIDO DE CANALES (Registrar o actualizar datos de cada canal/podcast)
        # =========================================================================
        
        for row in reader:
            if len(row) < 6:
                continue

            channel_id = row[0].strip()
            nombre_canal = row[1].strip()
            permitidas = row[2].strip()
            bloqueadas = row[3].strip()
            tipo = row[5].strip().lower()

            if 'podcast' not in tipo or not channel_id:
                continue

            print(f"\n🔍 Procesando podcast: {nombre_canal} ({channel_id})...")

            yt_channel_url = f"https://www.googleapis.com/youtube/v3/channels?part=snippet,contentDetails&id={channel_id}&key={API_KEY}"
            raw_chan = obtener_json_con_selenium(driver, yt_channel_url)
            res_chan = json.loads(raw_chan)

            if not res_chan.get('items'):
                print("  ⚠️ No se encontró el canal.")
                continue

            snippet = res_chan['items'][0]['snippet']
            uploads_id = res_chan['items'][0]['contentDetails']['relatedPlaylists']['uploads']
            image_url = snippet.get('thumbnails', {}).get(
                'default', {}).get('url', '')
            channel_url = f"https://www.youtube.com/{snippet.get('customUrl', 'channel/' + channel_id)}"

            res_php = enviar_a_php_con_session(driver, PHP_ENDPOINT, {
                'token': TOKEN_SECRET,
                'action': 'obtener_o_crear_podcast',
                'nombre': nombre_canal,
                'channel_url': channel_url,
                'image_url': image_url
            })

            podcast_id = res_php.get('podcast_id')
            if not podcast_id:
                print(f"  ❌ Error en PHP al registrar el podcast: {res_php}")
                continue
            
            # Sincronización general de subidas del canal de YouTube
            yt_playlist_url = f"https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&playlistId={uploads_id}&maxResults=50&key={API_KEY}"
            raw_playlist = obtener_json_con_selenium(driver, yt_playlist_url)
            res_playlist = json.loads(raw_playlist)

            video_ids = [item['snippet']['resourceId']['videoId']
                         for item in res_playlist.get('items', [])]
            if not video_ids:
                continue

            yt_videos_url = f"https://www.googleapis.com/youtube/v3/videos?part=snippet,contentDetails,liveStreamingDetails&id={','.join(video_ids)}&key={API_KEY}"
            raw_videos = obtener_json_con_selenium(driver, yt_videos_url)
            res_videos = json.loads(raw_videos)

            for video in res_videos.get('items', []):
                yt_id = video['id']
                titulo = video['snippet']['title']

                if not cumple_filtros(titulo, permitidas, bloqueadas):
                    continue

                is_live = video['snippet'].get(
                    'liveBroadcastContent') == 'live'
                duration_iso = video['contentDetails'].get('duration', '')
                segundos = duracion_a_segundos(duration_iso)

                if not is_live and segundos < 300:
                    continue

                duracion_hms = "00:00:00" if is_live else iso8601_a_hhmmss(
                    duration_iso)
                status = 'LIVE' if is_live else 'PUBLISHED'

                utc_dt = datetime.strptime(
                    video["snippet"]["publishedAt"], "%Y-%m-%dT%H:%M:%SZ"
                ).replace(tzinfo=ZoneInfo("UTC"))
                chile_dt = utc_dt.astimezone(ZoneInfo("America/Santiago"))
                publish_date = chile_dt.strftime("%Y-%m-%d %H:%M:%S")

                resp_insert = enviar_a_php_con_session(driver, PHP_ENDPOINT, {
                    'token': TOKEN_SECRET,
                    'action': 'guardar_episodio',
                    'youtube_id': yt_id,
                    'podcast_id': podcast_id,
                    'title': titulo,
                    'duration': duracion_hms,
                    'status': status,
                    'publish_date': publish_date
                })

                print(
                    f"  -> Video canal {yt_id}: {resp_insert.get('status')} ({resp_insert.get('message', '')})")
                
                            # 3. Sincronización inteligente utilizando la playlist global ya extraída
        # =========================================================================
        # 2. SINCRONIZACIÓN DE LA PLAYLIST GENERAL (Se hace UNA SOLA VEZ al final)
        # =========================================================================
        if PLAYLIST_URL:
            print(f"\n📋 Sincronizando playlist general de pendientes...")
            videos_yt = obtener_videos_playlist(PLAYLIST_URL)
            playlist_ids_general = {v["youtube_id"].strip(): v for v in videos_yt}

            if len(playlist_ids_general) == 0:
                print("⚠️ ADVERTENCIA: La playlist general devolvió 0 videos. Omitiendo sincronización.")
            else:
                print(f"  ✅ Se encontraron {len(playlist_ids_general)} videos en la playlist general.")
                sincronizar_playlist_pendientes(
                    driver, PHP_ENDPOINT, podcast_id, playlist_ids_general
                )


    finally:
        print("\n🔒 Cerrando navegador Selenium...")
        driver.quit()


if __name__ == '__main__':
    procesar()
