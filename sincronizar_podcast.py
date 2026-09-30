import time
import csv
import json
import requests  # Importante para enviar a PHP sin bloqueos CORS
from datetime import datetime
import isodate
from zoneinfo import ZoneInfo  # Requiere Python 3.9+

from navegador import configurar_navegador

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from webdriver_manager.chrome import ChromeDriverManager

import os
from dotenv import load_dotenv

DOWNLOAD_DIR = r"C:\Users\jvargas\Phyton\Descargar_Animes\descargas"

# Cargar las variables
load_dotenv()

# Obtener el link o ruta del CSV
CSV_URL = os.getenv("CSV_URL")
API_KEY = os.getenv("API_KEY")

# URL de tu script PHP subido a InfinityFree
# PHP_ENDPOINT = "http://tu-subdominio.epizy.com/api_insertar.php"

PHP_ENDPOINT = "http://localhost/Podcast_Manager/api_insertar.php"

TOKEN_SECRET = "MI_CLAVE_SUPER_SECRETA_123"
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
        bloqueadas = [p.strip().lower() for p in bloqueadas_str.split(',') if p.strip()]
        for p in bloqueadas:
            if p in titulo_lower:
                return False

    if permitidas_str:
        permitidas = [p.strip().lower() for p in permitidas_str.split(',') if p.strip()]
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

def enviar_a_php_con_session(driver, payload):
    """Envía POST a InfinityFree heredando las cookies reales del navegador Selenium."""
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    }
    
    # Extraer las cookies que InfinityFree le dio a Selenium
    selenium_cookies = driver.get_cookies()
    session = requests.Session()
    
    for cookie in selenium_cookies:
        session.cookies.set(cookie['name'], cookie['value'])

    try:
        res = session.post(PHP_ENDPOINT, data=payload, headers=headers, timeout=15)
        
        try:
            return res.json()
        except Exception:
            print(f"\n⚠️ [RESPUESTA RAW DE INFINITYFREE]:\n{res.text[:400]}\n")
            return {"status": "error", "message": "Servidor devolvió HTML/Error de PHP"}
            
    except Exception as e:
        return {"status": "error", "message": str(e)}
# PROCESO PRINCIPAL
# ------------------------------------------------------------------
def procesar():
    print("🌐 Iniciando navegador Selenium...")
    driver = configurar_navegador(DOWNLOAD_DIR)
    
    try:
        driver.get("https://www.google.com")
        time.sleep(1)

        print("📄 Descargando configuración desde Google Sheets CSV...")
        raw_csv = obtener_json_con_selenium(driver, CSV_URL)
        
        lines = raw_csv.splitlines()
        reader = csv.reader(lines)
        header = next(reader, None)
        
        # 1. Abrir la URL de tu API en InfinityFree con Selenium para obtener las cookies de seguridad
        print("🔑 Pasando validación de seguridad de InfinityFree...")
        driver.get(PHP_ENDPOINT)
        time.sleep(3) # Espera a que InfinityFree redirija y entregue la cookie de s

        for row in reader:
            if len(row) < 6:
                continue

            channel_id   = row[0].strip()
            nombre_canal = row[1].strip()
            permitidas   = row[2].strip()
            bloqueadas   = row[3].strip()
            tipo         = row[5].strip().lower()

            if 'podcast' not in tipo or not channel_id:
                continue

            print(f"\n🔍 Procesando podcast: {nombre_canal} ({channel_id})...")

            # 1. Consultar YouTube con Selenium
            yt_channel_url = f"https://www.googleapis.com/youtube/v3/channels?part=snippet,contentDetails&id={channel_id}&key={API_KEY}"
            raw_chan = obtener_json_con_selenium(driver, yt_channel_url)
            res_chan = json.loads(raw_chan)

            if not res_chan.get('items'):
                print("  ⚠️ No se encontró el canal.")
                continue

            snippet = res_chan['items'][0]['snippet']
            uploads_id = res_chan['items'][0]['contentDetails']['relatedPlaylists']['uploads']
            image_url = snippet.get('thumbnails', {}).get('default', {}).get('url', '')
            channel_url = f"https://www.youtube.com/{snippet.get('customUrl', 'channel/' + channel_id)}"

            # 2. Registrar en PHP (vía requests para evitar CORS)
            res_php = enviar_a_php_con_session(driver, {
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

            # 3. Obtener videos de YouTube con Selenium
            yt_playlist_url = f"https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&playlistId={uploads_id}&maxResults=50&key={API_KEY}"
            raw_playlist = obtener_json_con_selenium(driver, yt_playlist_url)
            res_playlist = json.loads(raw_playlist)

            video_ids = [item['snippet']['resourceId']['videoId'] for item in res_playlist.get('items', [])]
            if not video_ids:
                continue

            # 4. Consultar detalles en lote con Selenium
            yt_videos_url = f"https://www.googleapis.com/youtube/v3/videos?part=snippet,contentDetails,liveStreamingDetails&id={','.join(video_ids)}&key={API_KEY}"
            raw_videos = obtener_json_con_selenium(driver, yt_videos_url)
            res_videos = json.loads(raw_videos)

            for video in res_videos.get('items', []):
                yt_id = video['id']
                titulo = video['snippet']['title']

                if not cumple_filtros(titulo, permitidas, bloqueadas):
                    print(f"  🚫 Omitido por filtro de palabras: {titulo}")
                    continue

                is_live = video['snippet'].get('liveBroadcastContent') == 'live'
                duration_iso = video['contentDetails'].get('duration', '')
                segundos = duracion_a_segundos(duration_iso)

                if not is_live and segundos < 300:
                    print(f"  ⏱ Omitido por duración corta ({segundos}s): {titulo}")
                    continue

                duracion_hms = "00:00:00" if is_live else iso8601_a_hhmmss(duration_iso)
                status = 'LIVE' if is_live else 'PUBLISHED'
                # 1. Parsear el string de YouTube como UTC (reemplazando la 'Z' por '+00:00' o especificando UTC)
                utc_dt = datetime.strptime(
                    video["snippet"]["publishedAt"], "%Y-%m-%dT%H:%M:%SZ"
                ).replace(tzinfo=ZoneInfo("UTC"))

                # 2. Convertir la fecha a la hora de Chile (America/Santiago maneja automáticamente invierno/verano)
                chile_dt = utc_dt.astimezone(ZoneInfo("America/Santiago"))

                # 3. Formatearlo para guardarlo en tu base de datos MySQL (campo DATETIME)
                publish_date = chile_dt.strftime("%Y-%m-%d %H:%M:%S")

                # 5. Insertar episodio vía PHP (Evita CORS)
                resp_insert = enviar_a_php_con_session(driver,{
                    'token': TOKEN_SECRET,
                    'action': 'guardar_episodio',
                    'youtube_id': yt_id,
                    'podcast_id': podcast_id,
                    'title': titulo,
                    'duration': duracion_hms,
                    'status': status,
                    'publish_date': publish_date
                })
                
                print(f"  -> Video {yt_id}: {resp_insert.get('status')} ({resp_insert.get('message', '')})")

    finally:
        print("\n🔒 Cerrando navegador Selenium...")
        driver.quit()

if __name__ == '__main__':
    procesar()