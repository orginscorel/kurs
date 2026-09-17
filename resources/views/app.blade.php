<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="kurs-node" content="{{ config('kurs.node') }}">
    <meta name="theme-color" content="#4b3fe3">
    <title>Erbaa Bilgi Eğitim</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <script>
        // Tema: ilk boyamadan önce uygula (yanıp sönme olmasın)
        try {
            var t = localStorage.getItem('ebe-theme');
            if (t === 'dark' || (t !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.dataset.theme = 'dark';
            }
        } catch (e) {}
    </script>
    @viteReactRefresh
    @vite('resources/js/main.tsx')
</head>
<body>
    <div id="root"></div>
    <noscript>Erbaa Bilgi Eğitim için JavaScript'in açık olması gerekir.</noscript>
</body>
</html>
