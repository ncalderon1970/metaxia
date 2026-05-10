        </section>
    </main>
</div>

<script>
(function () {
    const toggle = document.querySelector('[data-metis-toggle]');
    const closers = document.querySelectorAll('[data-metis-close]');

    function openSidebar() {
        document.body.classList.add('metis-sidebar-open');
    }

    function closeSidebar() {
        document.body.classList.remove('metis-sidebar-open');
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (document.body.classList.contains('metis-sidebar-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    closers.forEach(function (el) {
        el.addEventListener('click', closeSidebar);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    document.querySelectorAll('.metis-nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 1050) {
                closeSidebar();
            }
        });
    });
})();
</script>

</body>
</html>