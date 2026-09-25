<#
  Statik PHP CLI (Windows x64) — static-php-cli (SPC) ile. GitHub Actions windows-latest'te çalışır.
  Kullanım:  pwsh scripts/build-static-php-win.ps1 <cikti-php.exe-yolu>
  Ortam:
    SPC_VERSION     static-php-cli sürümü (varsayılan 2.8.5) — macOS betiğiyle AYNI olmalı
    PHP_VERSION     PHP ana sürümü (varsayılan 8.4)
    PHP_EXTENSIONS  uzantı listesi (varsayılan aşağıda) — Windows'ta pcntl/posix YOK (Unix'e özel)
    PHP_LIBS        gd kitaplıkları
    SPC_WORK_DIR    derleme klasörü
  Not: macOS uzantı setinden 'pcntl' ve 'posix' çıkarıldı (Windows'ta desteklenmez). Gerisi aynı:
       Laravel + dompdf + openspout + intervention/image + eşitleme (sodium) + Türkçe sıralama (intl/ICU).
#>
$ErrorActionPreference = 'Stop'
if (-not $args -or -not $args[0]) { throw 'Kullanım: build-static-php-win.ps1 <cikti-yolu>' }
$Out = $args[0]

$SpcVersion = if ($env:SPC_VERSION) { $env:SPC_VERSION } else { '2.8.5' }
$PhpVersion = if ($env:PHP_VERSION) { $env:PHP_VERSION } else { '8.4' }
$Exts = if ($env:PHP_EXTENSIONS) { $env:PHP_EXTENSIONS } else { 'bcmath,ctype,curl,dom,exif,fileinfo,filter,gd,iconv,intl,mbstring,opcache,openssl,pdo,pdo_sqlite,phar,session,simplexml,sodium,sqlite3,tokenizer,xml,xmlreader,xmlwriter,zip,zlib' }
$Libs = if ($env:PHP_LIBS) { $env:PHP_LIBS } else { 'libpng,libjpeg,libwebp,freetype' }
$Work = if ($env:SPC_WORK_DIR) { $env:SPC_WORK_DIR } else { Join-Path (Get-Location) '.spc' }

New-Item -ItemType Directory -Force -Path $Work | Out-Null
Set-Location $Work

if (-not (Test-Path './spc.exe')) {
  Write-Host "static-php-cli $SpcVersion (windows-x64) indiriliyor"
  # SPC yayınında Windows binary'si doğrudan .exe'dir (zip DEĞİL) → doğrudan spc.exe olarak indir.
  curl.exe -fSL --retry 3 -o spc.exe "https://github.com/crazywhalecc/static-php-cli/releases/download/$SpcVersion/spc-windows-x64.exe"
}
& ./spc.exe --version

Write-Host 'Ortam denetimi'
& ./spc.exe doctor --auto-fix

Write-Host "Kaynaklar indiriliyor (PHP $PhpVersion)"
& ./spc.exe download --with-php="$PhpVersion" --for-extensions="$Exts" --for-libs="$Libs" --prefer-pre-built --retry=3

Write-Host "Derleniyor: $Exts"
& ./spc.exe build "$Exts" --with-libs="$Libs" --build-cli --disable-opcache-jit

$Bin = Join-Path $Work 'buildroot\bin\php.exe'
if (-not (Test-Path $Bin)) { throw "Çıktı bulunamadı: $Bin" }

Write-Host 'Doğrulama'
& $Bin -v
$modules = (& $Bin -m) -join "`n"
foreach ($e in @('bcmath','curl','dom','gd','iconv','intl','mbstring','openssl','pdo_sqlite','sodium','sqlite3','zip')) {
  if ($modules -notmatch "(?im)^\s*$([regex]::Escape($e))\s*$") { throw "EKSİK uzantı: $e" }
}
# Türkçe sıralama (ICU gömülü mü)
& $Bin -r '$c=new Collator("tr_TR"); $a=["Zeynep","Cagla","Ceren"]; $c->sort($a); exit(class_exists("Collator")?0:1);'
& $Bin -r '$p=new PDO("sqlite::memory:"); echo "sqlite ".$p->query("select sqlite_version()")->fetchColumn().PHP_EOL;'
& $Bin -r 'exit(sodium_crypto_box_keypair()?0:1); ' ; if ($LASTEXITCODE -ne 0) { throw 'sodium başarısız' }
& $Bin -r 'exit(function_exists("imagewebp") && function_exists("imagejpeg") ? 0 : 1);' ; if ($LASTEXITCODE -ne 0) { throw 'gd webp/jpeg eksik' }

New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Out) | Out-Null
Copy-Item -Force -Path $Bin -Destination $Out
Write-Host "OK: $Out"
