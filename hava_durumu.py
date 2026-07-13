"""
KONUM ALGILAYAN HAVA DURUMU UYGULAMASI
----------------------------------------
Cihazın IP adresinden konumunu algılar ve o ilin hava durumunu gösterir.
"""

import requests
import json
from datetime import datetime

def cihaz_konumunu_bul():
    """
    Cihazın IP adresinden konum bilgisini alır.
    """
    try:
        # IP'den konum bulma API'leri
        apis = [
            "http://ip-api.com/json/",
            "https://ipapi.co/json/",
            "http://ipinfo.io/json"
        ]
        
        for api in apis:
            try:
                cevap = requests.get(api, timeout=5)
                cevap.raise_for_status()
                veri = cevap.json()
                
                # Farklı API'lerden konum bilgisi çek
                if "city" in veri:
                    sehir = veri.get("city", "")
                    bolge = veri.get("region", "")
                    ulke = veri.get("country", "")
                elif "region" in veri:
                    sehir = veri.get("city", "")
                    bolge = veri.get("region", "")
                    ulke = veri.get("country_name", "")
                else:
                    sehir = veri.get("city", "")
                    bolge = veri.get("region", "")
                    ulke = veri.get("country", "")
                
                if sehir and ulke == "Turkey":
                    return {
                        "sehir": sehir,
                        "bolge": bolge,
                        "ulke": ulke,
                        "ip": veri.get("ip", "Bilinmiyor")
                    }
                    
            except:
                continue
                
        return None
        
    except Exception as e:
        print(f"Konum bulunamadı: {e}")
        return None

def sehir_koordinat_bul(sehir_adi):
    """
    Şehir adını koordinata çevirir.
    """
    url = "https://geocoding-api.open-meteo.com/v1/search"
    parametreler = {
        "name": sehir_adi,
        "count": 1,
        "language": "tr"
    }
    
    try:
        cevap = requests.get(url, params=parametreler, timeout=10)
        cevap.raise_for_status()
        veri = cevap.json()
        
        if veri.get("results"):
            sonuc = veri["results"][0]
            return {
                "lat": sonuc.get("latitude"),
                "lon": sonuc.get("longitude"),
                "isim": sonuc.get("name"),
                "ulke": sonuc.get("country")
            }
        return None
        
    except:
        return None

def hava_durumu_getir(lat, lon):
    """
    Koordinatlara göre hava durumu getirir.
    """
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
        return cevap.json().get("current", {})
    except:
        return None

def weather_code_to_text(code):
    """Hava durumu kodunu metne çevirir."""
    codes = {
        0: "Açık", 1: "Az Bulutlu", 2: "Parçalı Bulutlu", 3: "Kapalı",
        45: "Sisli", 48: "Sisli",
        51: "Hafif Çisenti", 53: "Çisenti", 55: "Yoğun Çisenti",
        61: "Hafif Yağmur", 63: "Yağmur", 65: "Yoğun Yağmur",
        71: "Hafif Kar", 73: "Kar", 75: "Yoğun Kar",
        80: "Sağanak Yağmur", 81: "Sağanak", 82: "Şiddetli Sağanak",
        95: "Gök Gürültülü", 96: "Gök Gürültülü", 99: "Gök Gürültülü"
    }
    return codes.get(code, "Bilinmiyor")

def html_olustur(sehir, hava, konum):
    """index.html oluşturur."""
    
    if hava:
        sicaklik = hava.get('temperature_2m', 'N/A')
        nem = hava.get('relative_humidity_2m', 'N/A')
        ruzgar = hava.get('wind_speed_10m', 'N/A')
        durum = weather_code_to_text(hava.get('weather_code', 0))
        
        # Emoji
        if sicaklik != 'N/A':
            emoji = "☀️" if sicaklik > 25 else "🌤️" if sicaklik > 15 else "☁️" if sicaklik > 5 else "❄️"
        else:
            emoji = "🌡️"
    else:
        sicaklik = nem = ruzgar = durum = "N/A"
        emoji = "❌"
    
    html = f"""
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{sehir} Hava Durumu</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }}
        
        .container {{
            background: rgba(255,255,255,0.95);
            border-radius: 30px;
            padding: 50px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }}
        
        .location {{
            color: #4a5568;
            font-size: 0.9em;
            margin-bottom: 10px;
        }}
        
        .city {{
            font-size: 2.5em;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }}
        
        .date {{
            color: #718096;
            font-size: 0.95em;
            margin-bottom: 30px;
        }}
        
        .weather-icon {{
            font-size: 5em;
            margin: 20px 0;
        }}
        
        .temperature {{
            font-size: 4em;
            font-weight: bold;
            color: #2d3748;
            margin: 10px 0;
        }}
        
        .weather-desc {{
            font-size: 1.3em;
            color: #4a5568;
            margin-bottom: 20px;
        }}
        
        .details {{
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-top: 30px;
        }}
        
        .detail-item {{
            background: #f7fafc;
            padding: 15px;
            border-radius: 15px;
        }}
        
        .detail-label {{
            font-size: 0.8em;
            color: #718096;
            margin-bottom: 5px;
        }}
        
        .detail-value {{
            font-size: 1.1em;
            font-weight: bold;
            color: #2d3748;
        }}
        
        .ip-info {{
            margin-top: 30px;
            padding: 15px;
            background: #edf2f7;
            border-radius: 10px;
            color: #4a5568;
            font-size: 0.85em;
        }}
        
        @media (max-width: 500px) {{
            .container {{
                padding: 30px 20px;
            }}
            
            .city {{
                font-size: 2em;
            }}
            
            .temperature {{
                font-size: 3em;
            }}
            
            .details {{
                grid-template-columns: 1fr;
            }}
        }}
    </style>
</head>
<body>
    <div class="container">
        <div class="location">📍 Bulunduğunuz Konum</div>
        <div class="city">{sehir}</div>
        <div class="date">{datetime.now().strftime('%d %B %Y, %H:%M')}</div>
        
        <div class="weather-icon">{emoji}</div>
        <div class="temperature">{sicaklik}°C</div>
        <div class="weather-desc">{durum}</div>
        
        <div class="details">
            <div class="detail-item">
                <div class="detail-label">💧 Nem</div>
                <div class="detail-value">%{nem}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">💨 Rüzgar</div>
                <div class="detail-value">{ruzgar} km/s</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">🌡️ Sıcaklık</div>
                <div class="detail-value">{sicaklik}°C</div>
            </div>
        </div>
        
        <div class="ip-info">
            🌐 IP: {konum.get('ip', 'Bilinmiyor')} | 
            📍 {konum.get('bolge', '')}
        </div>
    </div>
</body>
</html>
"""
    
    with open("index.html", "w", encoding="utf-8") as f:
        f.write(html)
    print("✅ index.html oluşturuldu!")

def ana_program():
    print("📍 KONUM ALGILAYAN HAVA DURUMU")
    print("=" * 40)
    
    # Cihaz konumunu bul
    print("🔍 Cihaz konumu tespit ediliyor...")
    konum = cihaz_konumunu_bul()
    
    if not konum:
        print("❌ Konum bulunamadı! İnternet bağlantısını kontrol et!")
        return
    
    sehir = konum.get("sehir")
    print(f"✅ Bulunduğunuz il: {sehir}")
    print(f"📍 Bölge: {konum.get('bolge')}")
    print(f"🌐 IP: {konum.get('ip')}")
    
    # Şehir koordinatlarını bul
    print(f"🔍 {sehir} koordinatları aranıyor...")
    koord = sehir_koordinat_bul(sehir)
    
    if not koord:
        print(f"❌ {sehir} bulunamadı!")
        return
    
    print(f"✅ {koord['isim']} bulundu!")
    
    # Hava durumunu al
    print("🌡️ Hava durumu alınıyor...")
    hava = hava_durumu_getir(koord["lat"], koord["lon"])
    
    if not hava:
        print("❌ Hava durumu alınamadı!")
        return
    
    print("✅ Hava durumu alındı!")
    
    # HTML oluştur
    html_olustur(sehir, hava, konum)
    
    print("=" * 40)
    print("🎉 TAMAM! index.html dosyasını aç!")
    print(f"🌤️ {sehir} hava durumu gösteriliyor!")

if __name__ == "__main__":
    ana_program()
