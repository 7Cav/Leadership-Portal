<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>
    {{--
      Apply the saved light/dark choice before first paint (Story 1.15), falling back to the OS
      setting. Must run before the stylesheet renders anything, hence inline and synchronous.
      The key matches THEME_STORAGE_KEY in resources/js/composables/useTheme.js.
    --}}
    <script>
        (function () {
            var theme = null;
            try { theme = localStorage.getItem('portal-theme'); } catch (e) {}
            if (theme !== 'light' && theme !== 'dark') {
                theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.classList.toggle('dark', theme === 'dark');
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    {{-- @inertia is re-registered in AppServiceProvider so the page JSON can't close its own script tag. --}}
    @inertia
</body>
</html>
