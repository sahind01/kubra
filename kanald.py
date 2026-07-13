import requests
from bs4 import BeautifulSoup
import json
import os
import re
from tqdm import tqdm
import urllib3
import subprocess
from datetime import datetime

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# --- AYARLAR ---
BASE_URL = "https://www.kanald.com.tr"
ROOT_DIR = "kanald"
DIRS = {
    "series": os.path.join(ROOT_DIR, "dizi"),
    "programs": os.path.join(ROOT_DIR, "program")
}

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
    "Referer": BASE_URL
}

def run_command(command):
    try:
        subprocess.run(command, shell=True, check=False)
    except: pass

def get_stream_url(page_url):
    """Video sayfasından m3u8 linkini regex ile söker"""
    try:
        r = requests.get(page_url, headers=HEADERS, verify=False, timeout=10)
        # KanalD genelde "mediaSourcesList" içinde tutar
        match = re.search(r'"Path":"(https:.*?\.m3u8.*?)"', r.text)
        if match:
            return match.group(1).replace("\\/", "/")
        
        # Alternatif: data-media-sources
        match2 = re.search(r"data-media-sources='(.*?)'", r.text)
        if match2:
            try:
                data = json.loads(match2.group(1))
                # Hls > Path
                return data.get("Hls", {}).get("Path", "")
            except: pass
            
    except: pass
    return None

def get_episodes(show_url, show_name):
    """Bir dizinin tüm bölümlerini tarar"""
    episodes = []
    page = 1
    # KanalD sayfalama yapısı: /dizi/bolumler?page=1
    # Bazen direkt hepsi tek sayfadadır.
    
    # Önce ana bölüm sayfasına gidelim
    bolumler_url = show_url + "/bolumler"
    print(f"   🔎 Bölümler aranıyor: {show_name}")
    
    try:
        while True:
            target_url = f"{bolumler_url}?page={page}"
            r = requests.get(target_url, headers=HEADERS, verify=False, timeout=10)
            soup = BeautifulSoup(r.content, "html.parser")
            
            cards = soup.select(".listing-holder .item")
            if not cards: break
            
            print(f"      Sayfa {page}: {len(cards)} içerik bulundu.")
            
            new_found = 0
            for card in cards:
                try:
                    a_tag = card.find("a")
                    if not a_tag: continue
                    link = BASE_URL + a_tag.get("href")
                    
                    title_tag = card.find("h3")
                    title = title_tag.get_text(strip=True) if title_tag else "Bolum"
                    
                    img_tag = card.find("img")
                    img = img_tag.get("data-src") or img_tag.get("src") if img_tag else ""
                    
                    # Video Linkini Çek (Her bölüm için istek atar - Biraz yavaş olabilir)
                    stream = get_stream_url(link)
                    
                    if stream:
                        episodes.append({
                            "name": title,
                            "img": img,
                            "stream_url": stream
                        })
                        new_found += 1
                except: continue
            
            if new_found == 0: break # Bu sayfada hiç video bulamadıysak bitir
            page += 1
            
    except Exception as e:
        print(f"   ⚠️ Hata: {e}")

    print(f"   ✅ Toplam {len(episodes)} oynatılabilir bölüm.")
    return episodes

def collect_shows(category_url):
    print(f"🌍 Kategori Taranıyor: {category_url}")
    shows = []
    try:
        r = requests.get(category_url, headers=HEADERS, verify=False, timeout=15)
        soup = BeautifulSoup(r.content, "html.parser")
        # Kartları bul
        cards = soup.select(".listing-holder .item, .program-list .item")
        
        for card in cards:
            a = card.find("a")
            if not a: continue
            url = BASE_URL + a.get("href")
            
            t_tag = card.find("h3") or card.find("img")
            name = t_tag.get_text(strip=True) if t_tag else "Bilinmeyen"
            if not name and t_tag.name == "img": name = t_tag.get("alt")
            
            img_tag = card.find("img")
            img = img_tag.get("data-src") or img_tag.get("src") if img_tag else ""
            
            shows.append({"name": name, "url": url, "img": img})
            
    except Exception as e:
        print(f"❌ Kategori Hatası: {e}")
        
    print(f"✅ {len(shows)} dizi/program bulundu.")
    return shows

def save_and_push(data, category):
    target_dir = DIRS[category]
    all_list = []
    
    for show in data:
        if not show.get("episodes"): continue
        
        slug = re.sub(r'[^a-z0-9-]', '', show['name'].lower().replace(" ", "-").replace("ç","c").replace("ğ","g").replace("ı","i").replace("ö","o").replace("ş","s").replace("ü","u"))
        
        # JSON
        with open(os.path.join(target_dir, f"{slug}.json"), "w", encoding="utf-8") as f:
            json.dump(show, f, ensure_ascii=False, indent=4)
        
        # M3U
        try:
            with open(os.path.join(target_dir, f"{slug}.m3u"), "w", encoding="utf-8") as f:
                f.write("#EXTM3U\n")
                for ep in show["episodes"]:
                    f.write(f'#EXTINF:-1 tvg-logo="{ep["img"]}",{ep["name"]}\n{ep["stream_url"]}\n')
        except: pass
        
        all_list.append(show)

    # Toplu
    with open(os.path.join(ROOT_DIR, f"kanald-{category}.json"), "w", encoding="utf-8") as f:
        json.dump(all_list, f, ensure_ascii=False, indent=4)
    with open(os.path.join(ROOT_DIR, f"kanald-{category}.m3u"), "w", encoding="utf-8") as f:
        f.write("#EXTM3U\n")
        for show in all_list:
            for ep in show["episodes"]:
                f.write(f'#EXTINF:-1 tvg-logo="{ep["img"]}" group-title="{show["name"]}",{ep["name"]}\n{ep["stream_url"]}\n')

def main():
    for d in DIRS.values(): os.makedirs(d, exist_ok=True)
    
    targets = [
        {"url": f"{BASE_URL}/diziler", "type": "series"},
        {"url": f"{BASE_URL}/programlar", "type": "programs"}
    ]
    
    for t in targets:
        shows = collect_shows(t["url"])
        processed = []
        
        for show in tqdm(shows, desc=f"{t['type']} İşleniyor"):
            episodes = get_episodes(show["url"], show["name"])
            if episodes:
                show["episodes"] = episodes
                processed.append(show)
        
        save_and_push(processed, t["type"])

    # GITHUB
    print("\n🚀 GitHub'a Yükleniyor...")
    run_command("git add --all")
    run_command(f'git commit -m "KanalD Guncelleme {datetime.now().strftime("%Y-%m-%d")}"')
    run_command("git push")
    print("✅ İşlem Tamamlandı.")

if __name__ == "__main__":
    main()
