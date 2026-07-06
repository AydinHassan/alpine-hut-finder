<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Hut beds, last minute — Austria</title>
    <meta
      name="description"
      content="Austrian Alpine huts with free beds in the next two weeks, sorted by distance from you."
    />
    <script>
      if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.classList.add('dark');
      }
      window.__HUTS__ = @json($payload);
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
  </head>
  <body>
    <div id="app"></div>
  </body>
</html>
