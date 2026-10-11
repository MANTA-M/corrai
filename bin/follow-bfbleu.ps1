# Ouvre Windows Terminal avec 2 onglets SSH vers bfbleu :
# 1) tunnel 8883 + suivi du log d'erreur corrai_test
# 2) suivi du consumer PHP corrai_test

$ErrorActionPreference = "Stop"

if (-not (Get-Command wt -ErrorAction SilentlyContinue)) {
    Write-Error "Windows Terminal (wt) introuvable dans le PATH."
    exit 1
}

# Utiliser && (pas ;) : le ; est le séparateur d'onglets de wt
$logCmd = "clear && tail -n 0 -f /var/log/corrai/corrai_test_error.log"
$consumerCmd = "./corrai_test/bin/follow-php-consumer"

# -w -1 : nouvelle fenêtre ; `; échappe le séparateur d'onglets pour PowerShell
wt -w -1 `
    new-tab --title "Corrai log" ssh -t -L 8888:127.0.0.1:8888 bfbleu $logCmd `; `
    new-tab --title "PHP consumer" ssh -t bfbleu $consumerCmd
