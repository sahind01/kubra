import requests
import re
import time
import json
import os

# GitHub RAW URL - JSON dosyasının bulunduğu yer
GITHUB_RAW_URL = "https://raw.githubusercontent.com/sahind01/kubra/refs/heads/main/diziler/atv.json"

def slugify(text):
    text = text.lower()
    text = text.replace('ı', 'i').replace('ğ', 'g').replace('ü', 'u')
    text = text.replace('ş', 's').replace('ö', 'o').replace('ç', 'c')
    text = re.sub(r'[^a-z0-9]', '-', text)
    text = re.sub(r'-+', '-', text)
    return text.strip('-')

def load_existing_data():
    """GitHub'dan JSON dosyasını yükler"""
    try:
        print(f"📥 JSON dosyası GitHub'dan indiriliyor...")
        response = requests.get(GITHUB_RAW_URL, timeout=15)
        
        if response.status_code == 200:
            data = response.json()
            print(f"   ✅ {len(data)} dizi yüklendi")
            return data
        else:
            print(f"   ⚠️  Dosya bulunamadı (HTTP {response.status_code}), yeni dosya oluşturulacak")
            return []
    except requests.exceptions.RequestException as e:
        print(f"   ⚠️  İndirme hatası: {e}, yeni dosya oluşturulacak")
        return []
    except json.JSONDecodeError as e:
        print(f"   ⚠️  JSON parse hatası: {e}, yeni dosya oluşturulacak")
        return []

def save_data(data):
    """JSON dosyasını local'e kaydeder (GitHub Actions ile commit edilecek)"""
    filename = "atv.json"
    with open(filename, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=4)
    
    print(f"   💾 {filename} dosyası local'e kaydedildi")
    
    # GitHub Actions için çıktı oluştur
    try:
        with open(os.environ.get('GITHUB_OUTPUT', 'output.txt'), 'a') as f:
            f.write(f"updated=true\n")
            f.write(f"filename={filename}\n")
    except:
        pass
    
    return True

def get_current_series():
    """Sadece GÜNCEL DİZİLERİ al (/diziler sayfası)"""
    base = "https://www.atv.com.tr"
    series_list = []
    
    try:
        print("🔍 Güncel diziler sayfası taranıyor...")
        r = requests.get(f"{base}/diziler", headers={'User-Agent': 'Mozilla/5.0'}, timeout=10)
        
        pattern = r'<a href="/([^"]+)"[^>]*?class="[^"]*blankpage[^"]*"[^>]*?>.*?<img[^>]*?src="([^"]+)"[^>]*?alt="([^"]+)"'
        matches = re.findall(pattern, r.text, re.DOTALL)
        
        for slug, logo, name in matches:
            if any(x in slug.lower() for x in ['canli-yayin', 'fragman', 'programlar', 'haber']):
                continue
            series_list.append({
                'name': name.strip(),
                'slug': slug,
                'logo': logo.split('?u=')[1] if '?u=' in logo else logo
            })
        
        print(f"   {len(series_list)} güncel dizi bulundu")
        return series_list
    except Exception as e:
        print(f"   Hata: {e}")
        return []

def get_last_episode_number(series_slug):
    """Sadece son bölüm numarasını al"""
    try:
        bolumler_url = f"https://www.atv.com.tr/{series_slug}/bolumler"
        r = requests.get(bolumler_url, headers={'User-Agent': 'Mozilla/5.0'}, timeout=8)
        
        pattern = r'<option[^>]*value="/([^/]+)/(\d+)-bolum/izle"[^>]*>'
        matches = re.findall(pattern, r.text)
        
        episode_numbers = []
        for slug, ep_num in matches:
            if slug == series_slug:
                episode_numbers.append(int(ep_num))
        
        if episode_numbers:
            return max(episode_numbers)
        
        max_episodes = {
            'karadayi': 115, 'kara-para-ask': 200, 'avrupa-yakasi': 300,
            'eskiya-dunyaya-hukmdar-olmaz': 200, 'sen-anlat-karadeniz': 200,
            'hercai': 200, 'kurulus-osman': 300, 'kardeslerim': 200
        }
        
        max_to_check = max_episodes.get(series_slug, 100)
        start = max(1, max_to_check - 15)
        
        for i in range(max_to_check, start - 1, -1):
            test_url = f"https://www.atv.com.tr/{series_slug}/{i}-bolum/izle"
            try:
                test_r = requests.head(test_url, headers={'User-Agent': 'Mozilla/5.0'}, timeout=2, allow_redirects=True)
                if test_r.status_code < 400:
                    return i
            except:
                pass
            time.sleep(0.03)
    
    except Exception as e:
        print(f"    Hata: {e}")
    
    return None

def get_video_url(series_slug, episode_num):
    """Video URL'sini al"""
    try:
        ep_url = f"https://www.atv.com.tr/{series_slug}/{episode_num}-bolum/izle"
        headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Referer': 'https://www.atv.com.tr/'
        }
        
        r = requests.get(ep_url, headers=headers, timeout=10)
        
        pattern = r'"contentUrl"\s*:\s*"([^"]+)"'
        matches = re.findall(pattern, r.text)
        
        for url in matches:
            if any(x in url.lower() for x in ['.mp4', '.m3u8']):
                if 'i.tmgrup.com.trvideo/' in url:
                    filename = url.split('/')[-1]
                    match = re.match(r'([a-zA-Z0-9-]+)_(\d+)_', filename)
                    if match:
                        dizi_adı = match.group(1)
                        bölüm_no = int(match.group(2))
                        url = f"https://atv-vod.ercdn.net/{dizi_adı}/{bölüm_no:03d}/{dizi_adı}_{bölüm_no:03d}.smil/playlist.m3u8"
                return url
        
        patterns = [
            r'(https?://[^\s"\']+\.(?:mp4|m3u8)[^\s"\']*)',
            r'video-src="([^"]+)"'
        ]
        
        for pattern in patterns:
            matches = re.findall(pattern, r.text, re.IGNORECASE)
            for url in matches:
                if 'fragman' not in url.lower():
                    return url
                    
    except Exception as e:
        print(f"      Video URL hatası: {e}")
    
    return None

def create_episode_object(number, title, url):
    """Bölüm nesnesini yeni formatta oluşturur"""
    return {
        "number": number,
        "name": title,
        "sources": [
            {
                "url": url,
                "label": "İzleme Kaynağı"
            }
        ]
    }

def update_atv():
    """ATV JSON'ını güncelle (GitHub'dan oku, güncelle, kaydet)"""
    print("🚀 ATV GÜNCELLEYİCİ (GitHub Uyumlu - Yeni Format)")
    print("=" * 60)
    start_time = time.time()
    
    # GitHub'dan mevcut veriyi yükle
    existing_data = load_existing_data()
    print(f"📂 Mevcut JSON'da {len(existing_data)} dizi var")
    
    # Mevcut dizilerin ID'lerini ve son bölüm numaralarını al
    existing_series_map = {}
    for series in existing_data:
        series_id = series.get("id")
        if series_id:
            episodes = series.get("episodes", [])
            last_ep = max(episodes, key=lambda x: x.get("number", 0)) if episodes else None
            existing_series_map[series_id] = {
                "data": series,
                "last_episode": last_ep.get("number", 0) if last_ep else 0
            }
    
    # Güncel dizileri al
    print("\n" + "=" * 40)
    current_series = get_current_series()
    
    if not current_series:
        print("❌ Güncel dizi bulunamadı!")
        return
    
    print(f"📌 {len(current_series)} güncel dizi kontrol edilecek")
    print("-" * 40)
    
    updated_count = 0
    new_series_count = 0
    total_new_episodes = 0
    
    for idx, series in enumerate(current_series, 1):
        series_id = f"atv_{slugify(series['name'])}"
        
        if series_id in existing_series_map:
            print(f"\n[{idx}/{len(current_series)}] 📺 {series['name']}")
        else:
            print(f"\n[{idx}/{len(current_series)}] 🆕 {series['name']} (YENİ DİZİ!)")
        
        last_episode = get_last_episode_number(series['slug'])
        
        if not last_episode:
            print(f"    ⚠️  Bölüm bulunamadı")
            continue
        
        print(f"    📺 Son Bölüm: {last_episode}")
        
        if series_id in existing_series_map:
            existing_last = existing_series_map[series_id]["last_episode"]
            
            if last_episode > existing_last:
                print(f"    ✅ YENİ BÖLÜM! (Eski: {existing_last} -> Yeni: {last_episode})")
                print(f"       🎬 {last_episode}. Bölüm video alınıyor...")
                
                video_url = get_video_url(series['slug'], last_episode)
                
                if video_url:
                    # ⭐ YENİ FORMATTA BÖLÜM EKLE
                    new_episode = create_episode_object(
                        number=last_episode,
                        title=f"{last_episode}. Bölüm",
                        url=video_url
                    )
                    existing_series_map[series_id]["data"]["episodes"].append(new_episode)
                    
                    # Bölümleri sırala
                    existing_series_map[series_id]["data"]["episodes"] = sorted(
                        existing_series_map[series_id]["data"]["episodes"], 
                        key=lambda x: x.get("number", 0)
                    )
                    
                    updated_count += 1
                    total_new_episodes += 1
                    print(f"          ✅ {last_episode}. Bölüm eklendi (Yeni Format)!")
                else:
                    print(f"          ❌ Video bulunamadı")
            else:
                print(f"    ℹ️  Yeni bölüm yok")
        else:
            print(f"    🆕 Yeni dizi ekleniyor...")
            print(f"       🎬 {last_episode}. Bölüm video alınıyor...")
            
            video_url = get_video_url(series['slug'], last_episode)
            
            if video_url:
                poster_url = series['logo']
                if not poster_url:
                    poster_url = f"https://via.placeholder.com/300x450/15161a/ffffff?text={series['name'].replace(' ', '+')}"
                
                # ⭐ YENİ FORMATTA DİZİ OLUŞTUR
                new_series = {
                    "id": series_id,
                    "name": series['name'],
                    "overview": f"{series['name']} dizisinin tüm bölümleri - ATV",
                    "poster": poster_url,
                    "logo": poster_url,
                    "backdrop": poster_url,
                    "year": "",
                    "tmdb_score": 0,
                    "genres": ["Dram", "Aile"],
                    "categories": ["ATV Dizileri"],
                    "cast": [],
                    "episodes": [
                        create_episode_object(
                            number=last_episode,
                            title=f"{last_episode}. Bölüm",
                            url=video_url
                        )
                    ]
                }
                existing_data.append(new_series)
                new_series_count += 1
                total_new_episodes += 1
                print(f"          ✅ Yeni dizi eklendi! ({last_episode}. Bölüm - Yeni Format)")
            else:
                print(f"          ❌ Video bulunamadı")
        
        time.sleep(0.1)
    
    # Veriyi kaydet
    if updated_count > 0 or new_series_count > 0:
        save_data(existing_data)
        
        elapsed_time = time.time() - start_time
        print(f"\n" + "=" * 60)
        print("✅ GÜNCELLEME TAMAMLANDI!")
        print("=" * 60)
        print(f"📊 İSTATİSTİKLER:")
        print(f"   • Toplam Dizi: {len(existing_data)}")
        print(f"   • Yeni Dizi: {new_series_count}")
        print(f"   • Yeni Bölüm Eklenen Dizi: {updated_count}")
        print(f"   • Toplam Yeni Bölüm: {total_new_episodes}")
        print(f"   • Süre: {elapsed_time:.2f} saniye")
        print(f"   • JSON Dosyası: 'atv.json'")
        print("=" * 60)
    else:
        print(f"\n✅ Hiç değişiklik yok! (Süre: {time.time() - start_time:.2f} saniye)")

if __name__ == "__main__":
    update_atv()
