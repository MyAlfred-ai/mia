</div>
<script>
// Flash — disparition douce après 4 s (commun aux pages Modèles IA).
(function () {
    const flash = document.getElementById('llmFlash');
    if (!flash) return;
    setTimeout(() => {
        flash.style.transition = 'opacity .4s';
        flash.style.opacity = '0';
        setTimeout(() => flash.remove(), 400);
    }, 4000);
})();
</script>
</body>
</html>
