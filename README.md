# Izypower Cloud

À l'heure où j'écris ces lignes, Izypower n'expose pas d'API publique. Je me suis inspiré de https://github.com/StefanPlizga/izypower_cloud qui, par retro engineering (probablement en écoutant le trafic réseau), a permis de mettre au jour les appels API réalisés par l'application Izypower Cloud.

Ce script PHP vibe-codé a donc pour but de tester cette API en affichant l'état en temps réel des centrales photovoltaïques :

* production instantanée, du jour et de la veille
* température de l'onduleur
* disponibilité d'une mise à jour firmware

```
Date du relevé         : 2026-06-04 20:31:28
Production instantanée : 83 W
  ↳ PV1                : 21.3 W
  ↳ PV2                : 20 W
  ↳ PV3                : 21.1 W
  ↳ PV4                : 20.8 W
Production jour        : 10.53 kWh
Production veille      : 5.22 kWh
Température onduleur   : 25.6 °C
Firmware               : ✅  à jour
```

## Paramétrage

```bash
cp .env.dist .env
```

Éditer `.env` pour saisir vos identifiants de connexion à Izypower Cloud.

## Utilisation

```bash
php script.php
```

ou

```bash
docker run --rm -v "$(pwd):/app" php:cli php /app/script.php
```
