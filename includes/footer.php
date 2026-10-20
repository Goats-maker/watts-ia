</main>

<footer class="text-center text-secondary small py-4">
  <strong>Guardianes del Planeta</strong> · Ahorrá energía, cuidá el agua, reciclá el resto.<br>
  PHP + MySQL + Gemini + Chart.js · Solo se guardan números: la foto se descarta tras la extracción.
  Alineado con los ODS 7, 12 y 13.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php if ($jsExtra ?? false): ?><script><?= $jsExtra ?></script><?php endif; ?>
</body>
</html>
