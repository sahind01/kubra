import requests
import json
import datetime

# Hava durumu çekilecek şehirler (İstediğin kadar şehir ekleyebilirsin)
sehirler = {
    "Doğubayazıt": {"lat": 39.5453, "lon": 44.0836},
    "Ağrı Merkez": {"lat": 39.7191, "lon": 43.0503},
    "İstanbul": {"lat": 41.0082, "lon": 28.9784},
    "Ankara": {"lat": 39.9199, "lon": 32.9247},
    "İzmir": {"lat": 38.4127, "lon": 27.1384},
    "Bursa": {"lat": 40.1824, "lon": 29.0671},
    "Antalya": {"lat": 36.8969, "lon": 30.7133},
    "Adana": {"lat": 37.0000, "lon": 35.3213},
    "Diyarbakır": {"lat": 37.9144, "lon": 40.2306},
    "Gaziantep": {"lat": 37.0662, "lon": 37.3833},
    "Konya": {"lat": 37.8667, "lon": 32.4833},
    "Kayseri": {"lat": 38.7312, "lon": 35.4787},
    "Samsun": {"lat": 41.2867, "lon": 36.33},
    "Trabzon": {"lat": 41.0015, "lon": 39.7178},
    "Erzurum": {"lat": 39.9043, "lon": 41.2679},
    "Van": {"lat": 38.4924, "lon": 43.3831}
}

# İngilizce günleri Türkçeye çeviren sözlük
gun_cevir = {
    "Monday": "Pzt", "Tuesday": "Sal", "Wednesday": "Çar", 
    "Thursday": "Per", "Friday": "Cum", "Saturday": "Cts", "Sunday": "Paz"
}

def durum_analizi(kod):
    if kod == 0: return "☀️", "Açık"
    elif kod in [1, 2, 3]: return "⛅", "Parçalı Bulutlu"
    elif kod in [45, 48]: return "🌫️", "Sisli"
    elif kod in [51, 53, 55, 56, 57]: return "🌦️", "Çiseleyen"
    elif kod in [61, 63, 65, 66, 67, 80, 81, 82]: return "🌧️", "Yağmurlu"
    elif kod in [71, 73, 75, 77, 85, 86]: return "❄️", "Kar Yağışlı"
    elif kod in [95, 96, 99]: return "🌩️", "Fırtınalı"
    else: return "🌡️", "Belirsiz"

print("Tüm şehirlerin canlı ve 7 günlük verileri çekiliyor...")
tum_veriler = {}

for sehir, koord in sehirler.items():
    url = f"https://api.open-meteo.com/v1/forecast?latitude={koord['lat']}&longitude={koord['lon']}&current_weather=true&daily=weathercode,temperature_2m_max,temperature_2m_min&timezone=Europe%2FMoscow"
    cevap = requests.get(url)
    
    if cevap.status_code == 200:
        veri = cevap.json()
        
        # 1. Anlık Veriyi Al
        anlik = veri["current_weather"]
        ikon_anlik, durum_anlik = durum_analizi(anlik["weathercode"])
        
        anlik_paket = {
            "sicaklik": round(anlik["temperature"]),
            "ruzgar": anlik["windspeed"],
            "ikon": ikon_anlik,
            "durum": durum_anlik
        }
        
        # 2. Haftalık Veriyi Al (7 Gün)
        haftalik_paket = []
        daily = veri["daily"]
        
        for i in range(7):
            tarih_str = daily["time"][i]
            tarih_obj = datetime.datetime.strptime(tarih_str, "%Y-%m-%d")
            
            # Bugünün adını "Bugün" yapıyoruz, diğerlerini Pzt, Sal vs.
            if i == 0:
                gun_adi = "Bugün"
            else:
                gun_ing = tarih_obj.strftime("%A")
                gun_adi = gun_cevir[gun_ing]
                
            ikon_gun, _ = durum_analizi(daily["weathercode"][i])
            haftalik_paket.append({
                "gun": gun_adi,
                "ikon": ikon_gun,
                "max": round(daily["temperature_2m_max"][i]),
                "min": round(daily["temperature_2m_min"][i])
            })
            
        tum_veriler[sehir] = {"anlik": anlik_paket, "haftalik": haftalik_paket}
        print(f"{sehir} başarıyla çekildi.")

# JavaScript ile çalışacak JSON formatına çeviriyoruz
json_verisi = json.dumps(tum_veriler, ensure_ascii=False)
zaman = datetime.datetime.now().strftime("%d-%m-%Y %H:%M")

html_icerik = f"""
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gelişmiş Hava Durumu - Mutlu IPTV</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {{ box-sizing: border-box; }}
        body {{ font-family: 'Poppins', sans-serif; background-color: #0f172a; color: #f8fafc; margin: 0; padding: 15px; }}
        
        /* Başlık Alanı */
        .header {{ text-align: center; margin-bottom: 20px; }}
        .baslik {{ font-size: 24px; font-weight: 800; color: #0ea5e9; margin-bottom: 4px; }}
        .alt-baslik {{ font-size: 13px; color: #94a3b8; font-weight: 500; }}
        
        /* Şehir Seçici Dropdown */
        .secici-kutu {{ text-align: center; margin-bottom: 25px; }}
        select {{ appearance: none; background-color: #1e293b; color: #f8fafc; border: 2px solid #334155; padding: 12px 40px 12px 20px; border-radius: 30px; font-size: 16px; font-weight: 600; outline: none; cursor: pointer; transition: 0.3s; box-shadow: 0 4px 6px rgba(0,0,0,0.2); background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%230ea5e9%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.4-12.8z%22%2F%3E%3C%2Fsvg%3E"); background-repeat: no-repeat; background-position: right 15px top 50%; background-size: 12px auto; }}
        select:focus {{ border-color: #0ea5e9; }}
        
        /* Anlık Hava Durumu Ana Kart */
        .ana-kart {{ background: linear-gradient(145deg, #1e293b, #0f172a); border-radius: 20px; padding: 30px 20px; text-align: center; border: 1px solid #334155; box-shadow: 0 10px 25px rgba(0,0,0,0.3); margin-bottom: 25px; }}
        #anlikIkon {{ font-size: 70px; line-height: 1; margin-bottom: 10px; display: block; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.4)); }}
        #anlikSicaklik {{ font-size: 48px; font-weight: 800; color: #fff; margin-bottom: 5px; }}
        #anlikDurum {{ font-size: 18px; font-weight: 600; color: #0ea5e9; margin-bottom: 15px; }}
        #anlikRuzgar {{ font-size: 13px; color: #94a3b8; font-weight: 500; background: rgba(255,255,255,0.05); display: inline-block; padding: 6px 15px; border-radius: 20px; }}
        
        /* 7 Günlük Tahmin Grid'i */
        .haftalik-baslik {{ font-size: 16px; font-weight: 600; color: #cbd5e1; margin-bottom: 15px; border-bottom: 2px solid #334155; padding-bottom: 8px; display: inline-block; }}
        .haftalik-grid {{ display: flex; overflow-x: auto; gap: 12px; padding-bottom: 15px; scrollbar-width: none; }}
        .haftalik-grid::-webkit-scrollbar {{ display: none; }}
        
        .gun-kart {{ min-width: 80px; background: #1e293b; border-radius: 16px; padding: 15px 10px; text-align: center; border: 1px solid #334155; flex-shrink: 0; }}
        .gun-isim {{ font-size: 13px; font-weight: 600; color: #94a3b8; margin-bottom: 8px; }}
        .gun-ikon {{ font-size: 28px; margin-bottom: 8px; }}
        .gun-dereceler {{ font-size: 14px; font-weight: 700; }}
        .derece-max {{ color: #f8fafc; }}
        .derece-min {{ color: #64748b; font-size: 12px; margin-left: 4px; }}
        
        .footer {{ text-align: center; margin-top: 30px; font-size: 12px; color: #64748b; line-height: 1.6; padding-bottom: 20px; }}
        .marka {{ color: #0ea5e9; font-weight: bold; }}
    </style>
</head>
<body>

    <div class="header">
        <div class="baslik">☁️ HAVA DURUMU</div>
        <div class="alt-baslik">Canlı & Haftalık Meteoroloji Paneli</div>
    </div>

    <div class="secici-kutu">
        <select id="sehirSelect" onchange="ekraniGuncelle()">
            </select>
    </div>

    <div class="ana-kart">
        <span id="anlikIkon">⏳</span>
        <div id="anlikSicaklik">--°C</div>
        <div id="anlikDurum">Yükleniyor...</div>
        <div id="anlikRuzgar">💨 Rüzgar: -- km/s</div>
    </div>

    <div class="haftalik-baslik">📅 7 Günlük Tahmin</div>
    <div class="haftalik-grid" id="haftalikKonteyner">
        </div>

    <div class="footer">
        © 2026 <span class="marka">Mutlu IPTV</span> Özel Servisi<br>
        Son Güncelleme: {zaman}
    </div>

    <script>
        // Python'un çektiği tüm veriyi buraya gizli veritabanı olarak gömüyoruz
        const havaVerileri = {json_verisi};
        
        const sehirSelect = document.getElementById("sehirSelect");
        const anlikIkon = document.getElementById("anlikIkon");
        const anlikSicaklik = document.getElementById("anlikSicaklik");
        const anlikDurum = document.getElementById("anlikDurum");
        const anlikRuzgar = document.getElementById("anlikRuzgar");
        const haftalikKonteyner = document.getElementById("haftalikKonteyner");

        // Sayfa açıldığında select kutusunu şehirlerle doldur
        function baslat() {{
            let ilkSehir = "Doğubayazıt"; // Senin şehrin varsayılan olarak seçili gelir
            
            for (let sehir in havaVerileri) {{
                let option = document.createElement("option");
                option.value = sehir;
                option.text = sehir;
                if (sehir === ilkSehir) option.selected = true;
                sehirSelect.appendChild(option);
            }}
            
            ekraniGuncelle(); // İlk veriyi ekrana bas
        }}

        // Seçilen şehre göre ekranı anında değiştirme
        function ekraniGuncelle() {{
            const secilenSehir = sehirSelect.value;
            const veri = havaVerileri[secilenSehir];
            
            // Üstteki Ana Kartı Güncelle
            anlikIkon.textContent = veri.anlik.ikon;
            anlikSicaklik.textContent = veri.anlik.sicaklik + "°C";
            anlikDurum.textContent = veri.anlik.durum;
            anlikRuzgar.textContent = "💨 Rüzgar: " + veri.anlik.ruzgar + " km/s";
            
            // Alttaki 7 Günlük Grid'i Güncelle
            haftalikKonteyner.innerHTML = ""; // Önceki kartları temizle
            
            veri.haftalik.forEach(gunData => {{
                let kart = document.createElement("div");
                kart.className = "gun-kart";
                kart.innerHTML = `
                    <div class="gun-isim">${{gunData.gun}}</div>
                    <div class="gun-ikon">${{gunData.ikon}}</div>
                    <div class="gun-dereceler">
                        <span class="derece-max">${{gunData.max}}°</span>
                        <span class="derece-min">${{gunData.min}}°</span>
                    </div>
                `;
                haftalikKonteyner.appendChild(kart);
            }});
        }}

        // Her şey hazır olunca başlat
        window.onload = baslat;
    </script>
</body>
</html>
"""

# Dosyayı kaydet
with open("index.html", "w", encoding="utf-8") as dosya:
    dosya.write(html_icerik)

print("İşlem tamam! Mükemmel altyapılı index.html oluşturuldu.")
