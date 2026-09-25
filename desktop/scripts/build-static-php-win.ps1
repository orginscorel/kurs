<#
  Resmi PHP (Windows x64, NTS) hazırlar.
  SPC statik derlemesi Windows'ta php-src extraction'da (7za|tar) sürekli patladığı için
  windows.php.net RESMİ binary'sine geçildi. Çıktı TEK exe DEĞİL, bir KLASÖRDÜR:
    php.exe + php8.dll + ICU DLL'leri + ext\php_*.dll + conf.d\ext.ini
  Uygulama runtime/php.ini'yi PHPRC ile okur (paylaşımlı ayarlar); Windows uzantıları buradaki
  conf.d\ext.ini'den PHP_INI_SCAN_DIR ile yüklenir. extension_dir çalışma zamanında ${KURS_PHP_EXT_DIR}.
  Kullanım: pwsh scripts/build-static-php-win.ps1 <cikti-klasoru>
  Ortam:   PHP_MINOR (varsayılan 8.4)
#>
$ErrorActionPreference = 'Stop'
if (-not $args -or -not $args[0]) { throw 'Kullanım: build-static-php-win.ps1 <cikti-klasoru>' }
$OutDir = $args[0]
$Minor  = if ($env:PHP_MINOR) { $env:PHP_MINOR } else { '8.4' }

if (Test-Path $OutDir) { Remove-Item -Recurse -Force $OutDir }
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$Tmp = Join-Path ([System.IO.Path]::GetTempPath()) ('php-dl-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $Tmp | Out-Null

# 1) Güncel NTS x64 zip adını resmi releases.json'dan al (vs17 → vs16)
Write-Host "PHP $Minor (NTS x64) sürümü belirleniyor (windows.php.net)"
$rel = Invoke-RestMethod -UseBasicParsing 'https://windows.php.net/downloads/releases/releases.json'
$branch = $rel.$Minor
if (-not $branch) { throw "PHP $Minor için Windows sürümü bulunamadı" }
$key = @('nts-vs17-x64','nts-vs16-x64') | Where-Object { $branch.$_ -and $branch.$_.zip } | Select-Object -First 1
if (-not $key) {
  $key = ($branch.PSObject.Properties | Where-Object { $_.Name -match 'nts' -and $_.Name -match 'x64' -and $_.Value.zip }).Name | Select-Object -First 1
}
if (-not $key) { throw "NTS x64 zip anahtarı bulunamadı (PHP $Minor)" }
$zipName = $branch.$key.zip.path
$ver     = $branch.version
$url     = "https://windows.php.net/downloads/releases/$zipName"
Write-Host "Sürüm $ver ($key) indiriliyor: $url"
$zip = Join-Path $Tmp $zipName
curl.exe -fSL --retry 3 -o $zip $url
if (-not (Test-Path $zip)) { throw "İndirilemedi: $url" }

# 2) Çıkar (php.exe + tüm kök DLL'ler + ext\ doğrudan $OutDir'e)
Write-Host "Çıkarılıyor → $OutDir"
Expand-Archive -Force -Path $zip -DestinationPath $OutDir
$php    = Join-Path $OutDir 'php.exe'
$extAbs = Join-Path $OutDir 'ext'
if (-not (Test-Path $php))    { throw "php.exe bulunamadı: $php" }
if (-not (Test-Path $extAbs)) { throw "ext klasörü bulunamadı: $extAbs" }

# 3) conf.d\ext.ini — PHP_INI_SCAN_DIR ile taranır; extension_dir çalışma zamanı ortam değişkeninden
$exts = @('openssl','mbstring','curl','exif','fileinfo','gd','intl','pdo_sqlite','sqlite3','sodium','zip')
$confd = Join-Path $OutDir 'conf.d'
New-Item -ItemType Directory -Force -Path $confd | Out-Null
$extLines = @(
  '; Erbaa Kurs — Windows uzantıları (resmi PHP). extension_dir çalışma zamanında ${KURS_PHP_EXT_DIR}.'
  'extension_dir = "${KURS_PHP_EXT_DIR}"'
  'zend_extension = opcache'
) + ($exts | ForEach-Object { "extension = $_" })
Set-Content -Path (Join-Path $confd 'ext.ini') -Value $extLines -Encoding ASCII

# 4) Doğrula (mutlak extension_dir ile geçici ini üzerinden)
Write-Host 'Doğrulama'
$valIni = Join-Path $Tmp 'validate.ini'
$valLines = @("extension_dir = `"$extAbs`"", 'zend_extension = opcache') + ($exts | ForEach-Object { "extension = $_" })
Set-Content -Path $valIni -Value $valLines -Encoding ASCII
& $php -n -c $valIni -v
if ($LASTEXITCODE -ne 0) { throw 'php -v başarısız' }
$mods = (& $php -n -c $valIni -m) -join "`n"
foreach ($e in @('intl','sodium','pdo_sqlite','gd','curl','mbstring','openssl','sqlite3','zip','fileinfo','exif')) {
  if ($mods -notmatch "(?im)^\s*$([regex]::Escape($e))\s*$") { throw "EKSİK uzantı: $e" }
}
& $php -n -c $valIni -r '$p=new PDO("sqlite::memory:"); echo "sqlite ".$p->query("select sqlite_version()")->fetchColumn().PHP_EOL;'
if ($LASTEXITCODE -ne 0) { throw 'pdo_sqlite başarısız' }
& $php -n -c $valIni -r '$c=new Collator("tr_TR"); exit(($c instanceof Collator) ? 0 : 1);'
if ($LASTEXITCODE -ne 0) { throw 'intl/Collator (ICU) başarısız' }
& $php -n -c $valIni -r 'exit(sodium_crypto_box_keypair()?0:1);'
if ($LASTEXITCODE -ne 0) { throw 'sodium başarısız' }

Remove-Item -Recurse -Force $Tmp
Write-Host "OK: resmi PHP $ver → $OutDir"
