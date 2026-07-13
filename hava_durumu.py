"""
Türkiye Hava Durumu Uygulaması - OTOMATİK
----------------------------------------
Tüm Türkiye illerini otomatik olarak API'den çeker.
Çalıştırıldığında index.html oluşturur.
"""

import requests
import json
from datetime import datetime

def tum_illeri_getir():
    """
    Open-Meteo Geocoding API'den Türkiye'deki tüm illeri otomatik olarak çeker.
    """
    url = "https://geocoding-api.open-meteo.com/v1/search"
    parametreler = {
        "name": "Türkiye",
        "count": 100,
        "language": "tr",
        "format": "json"
    }
    
    try:
        cevap = requests.get(url, params=parametreler, timeout=10)
        cevap.raise_for_status()
        veri = cevap.json()
        
        sehirler = {}
        for item in veri.get("results", []):
            # Sadece Türkiye'deki şehirleri al
            if item.get("country") == "Türkiye" or item.get("country_code") == "TR":
                sehir_adi = item.get("name")
                if sehir_adi and sehir_adi not in sehirler:
                    sehirler[sehir_adi] = {
                        "lat": item.get("latitude"),
                        "lon": item.get("longitude")
                    }
        
        return sehirler
        
    except requests.exceptions.RequestException as e:
        print(f"API hatası: {e}")
        return None

def hava_durumu_getir(lat, lon):
    """Belirtilen koordinatların hava durumu bilgisini getirir."""
    url = "https://api.open-meteo.com/v1/forecast"
    parametreler = {
        "latitude": lat,
        "longitude": lon,
        "current": "temperature_2m,relative_humidity_2m,wind_speed_10m,weather_code",
        "timezone": "Europe/Istanbul"
    }
    
    try:
        cevap = requests.get(url, params=parametreler, timeout=10)
        cevap.raise_for_status()
        veri = cevap.json()
        return veri.get("current", {})
    except:
        return None

def weather_code_to_text(code):
    """Hava durumu kodunu metne çevirir."""
    weather_codes = {
        0: "Açık",
        1: "Az Bulutlu",
        2: "Parçalı Bulutlu",
        3: "Kapalı",
        45: "Sisli",
        48: "Sisli",
        51: "Hafif Çisenti",
        53: "Çisenti",
        55: "Yoğun Çisenti",
        61: "Hafif Yağmur",
        63: "Yağmur",
        65: "Yoğun Yağmur",
        71: "Hafif Kar",
        73: "Kar",
        75: "Yoğun Kar",
        80: "Sağanak Yağmur",
        81: "Sağanak Yağmur",
        82: "Şiddetli Sağanak",
        95: "Gök Gürültülü",
        96: "Gök Gürültülü",
        99: "Gök Gürültülü"
    }
    return weather_codes.get(code, "Bilinmiyor")

def html_olustur(tum_veri):
    """Hava durumu verilerini içeren index.html dosyasını oluşturur."""
    
    html_icerik = f"""
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Türkiye Hava Durumu</title>
    <style>
        * {{
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }}
        
        body {{
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }}
        
        .container {{
            max-width: 1400px;
            margin: 0 auto;
        }}
        
        h1 {{
            text-align: center;
            color: white;
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }}
        
        .update-time {{
            text-align: center;
            color: rgba(255,255,255,0.8);
            margin-bottom: 30px;
            font-size: 1.1em;
        }}
        
        .weather-grid {{
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }}
        
        .weather-card {{
            background: rgba(255,255,255,0.95);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }}
        
        .weather-card:hover {{
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.3);
        }}
        
        .city-name {{
            font-size: 1.2em;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }}
        
        .temperature {{
            font-size: 2.2em;
            font-weight: bold;
            color: #2d3748;
            margin: 5px 0;
        }}
        
        .weather-desc {{
            color: #4a5568;
            font-size: 0.95em;
            margin: 5px 0;
        }}
        
        .humidity, .wind {{
            color: #718096;
            font-size: 0.85em;
            margin: 3px 0;
        }}
        
        .weather-icon {{
            font-size: 2.5em;
            margin: 5px 0;
        }}
        
        @media (max-width: 600px) {{
            .weather-grid {{
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 15px;
            }}
            
            h1 {{
                font-size: 1.8em;
            }}
            
            .temperature {{
                font-size: 1.8em;
            }}
        }}
        
        .error {{
            text-align: center;
            color: white;
            font-size: 1.5em;
            padding: 50px;
            background: rgba(255,0,0,0.3);
            border-radius: 10px;
        }}
    </style>
</head>
<body>
    <div class="container">
        <h1>🌤️ Türkiye Hava Durumu</h1>
        <div class="update-time">📅 Son Güncelleme: {datetime.now().strftime('%d.%m.%Y %H:%M:%S')}</div>
        <div class="weather-grid">
"""
    
    for sehir, veri in tum_veri.items():
        if veri:
            sicaklik = veri.get('temperature_2m', 'N/A')
            nem = veri.get('relative_humidity_2m', 'N/A')
            ruzgar = veri.get('wind_speed_10m', 'N/A')
            durum = weather_code_to_text(veri.get('weather_code', 0))
            
            # Emoji seçimi
            emoji = "☀️" if sicaklik > 25 else "🌤️" if sicaklik > 15 else "☁️" if sicaklik > 5 else "❄️"
            
            html_icerik += f"""
            <div class="weather-card">
                <div class="city-name">{sehir}</div>
                <div class="weather-icon">{emoji}</div>
                <div class="temperature">{sicaklik}°C</div>
                <div class="weather-desc">{durum}</div>
                <div class="humidity">💧 Nem: %{nem}</div>
                <div class="wind">💨 Rüzgar: {ruzgar} km/s</div>
            </div>
"""
    
    html_icerik += """
        </div>
    </div>
</body>
</html>
"""
    
    with open("index.html", "w", encoding="utf-8") as f:
        f.write(html_icerik)
    print("✅ index.html dosyası oluşturuldu!")

def ana_program():
    print("🚀 Türkiye Hava Durumu Uygulaması Başlatılıyor...")
    print("=" * 50)
    
    # Otomatik illeri çek
    print("📡 Türkiye illeri API'den alınıyor...")
    sehirler = tum_illeri_getir()
    
    if not sehirler:
        print("❌ İller alınamadı! İnternet bağlantınızı kontrol edin.")
        return
    
    print(f"✅ {len(sehirler)} il bulundu.")
    print("🌡️ Hava durumu bilgileri alınıyor...")
    
    # Her ilin hava durumunu al
    tum_hava_durumu = {}
    sayac = 0
    
    for sehir_adi, koord in sehirler.items():
        sayac += 1
        print(f"   {sayac}/{len(sehirler)}: {sehir_adi}...")
        
        hava = hava_durumu_getir(koord["lat"], koord["lon"])
        if hava:
            tum_hava_durumu[sehir_adi] = hava
    
    # HTML oluştur
    html_olustur(tum_hava_durumu)
    
    print("=" * 50)
    print("✅ Tüm işlemler tamamlandı!")
    print("📄 index.html dosyasını tarayıcıda açabilirsiniz.")
    print(f"🌆 Toplam {len(tum_hava_durumu)} ilin hava durumu gösteriliyor.")

if __name__ == "__main__":
    ana_program()
