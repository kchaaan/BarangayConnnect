/**
 * Admin header weather — uses browser location + Open-Meteo (no API key).
 */
(function () {
  const DEFAULT_LOCATION = { lat: 14.4791, lon: 121.0198 };
  const REFRESH_MS = 20 * 60 * 1000;
  let lastCoords = null;

  function weatherMeta(code) {
    const c = Number(code);
    if (c === 0) return { icon: 'fa-sun', tone: 'is-clear' };
    if (c >= 1 && c <= 3) return { icon: 'fa-cloud-sun', tone: 'is-partly' };
    if (c === 45 || c === 48) return { icon: 'fa-smog', tone: 'is-cloudy' };
    if (c >= 51 && c <= 67) return { icon: 'fa-cloud-rain', tone: 'is-rain' };
    if (c >= 71 && c <= 77) return { icon: 'fa-snowflake', tone: 'is-cloudy' };
    if (c >= 80 && c <= 82) return { icon: 'fa-cloud-showers-heavy', tone: 'is-rain' };
    if (c >= 95) return { icon: 'fa-bolt', tone: 'is-storm' };
    return { icon: 'fa-cloud', tone: 'is-cloudy' };
  }

  async function fetchWeather(lat, lon) {
    const url = new URL('https://api.open-meteo.com/v1/forecast');
    url.searchParams.set('latitude', String(lat));
    url.searchParams.set('longitude', String(lon));
    url.searchParams.set('current', 'temperature_2m,weather_code');
    url.searchParams.set('timezone', 'auto');

    const res = await fetch(url.href);
    if (!res.ok) throw new Error('Weather request failed');
    return res.json();
  }

  function renderWeather(temperature, weatherCode, coords) {
    const wrap = document.getElementById('adminHeaderWeather');
    const iconEl = document.getElementById('adminWeatherIcon');
    const tempEl = document.getElementById('adminWeatherTemp');
    if (!wrap || !iconEl || !tempEl) return;

    const meta = weatherMeta(weatherCode);
    iconEl.className = 'fas ' + meta.icon + ' admin-header-weather__icon ' + meta.tone;
    const temp = Number(temperature);
    tempEl.textContent = Number.isFinite(temp) ? Math.round(temp) + '°C' : '--°C';

    const label = coords && coords.fromGeo ? 'Weather near your location' : 'Weather (Tambo, Parañaque)';
    wrap.title = label;
    wrap.hidden = false;
  }

  async function loadWeather(lat, lon, fromGeo) {
    lastCoords = { lat, lon, fromGeo: !!fromGeo };
    try {
      const data = await fetchWeather(lat, lon);
      const current = data && data.current;
      if (!current) throw new Error('No current weather');
      renderWeather(current.temperature_2m, current.weather_code, lastCoords);
    } catch (err) {
      console.warn('Admin header weather:', err);
      const tempEl = document.getElementById('adminWeatherTemp');
      if (tempEl) tempEl.textContent = '--°C';
    }
  }

  function initAdminHeaderWeather() {
    const wrap = document.getElementById('adminHeaderWeather');
    if (!wrap) return;

    if (!navigator.geolocation) {
      loadWeather(DEFAULT_LOCATION.lat, DEFAULT_LOCATION.lon, false);
      return;
    }

    navigator.geolocation.getCurrentPosition(
      function (pos) {
        loadWeather(pos.coords.latitude, pos.coords.longitude, true);
      },
      function () {
        loadWeather(DEFAULT_LOCATION.lat, DEFAULT_LOCATION.lon, false);
      },
      { enableHighAccuracy: false, timeout: 15000, maximumAge: 600000 }
    );
  }

  window.initAdminHeaderWeather = initAdminHeaderWeather;

  document.addEventListener('DOMContentLoaded', initAdminHeaderWeather);

  setInterval(function () {
    if (lastCoords) {
      loadWeather(lastCoords.lat, lastCoords.lon, lastCoords.fromGeo);
    }
  }, REFRESH_MS);
})();
