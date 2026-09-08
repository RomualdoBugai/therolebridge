<!-- Loader Start -->
<div id="preloader">
    <div id="status">
        <div class="spinner">
            <div class="double-bounce1"></div>
            <div class="double-bounce2"></div>
        </div>
    </div>
</div>
<!-- Loader End -->

<!-- Start Navbar -->
<nav id="topnav" class="defaultscroll is-sticky bg-white! dark:bg-slate-900!">
    <div class="container relative">
        <!-- Logo container-->
        <a class="logo" href="index.php<?= $linkCompleteHref ?>">
            <img src="assets/images/<?= $logoDark ?>" class="inline-block dark:hidden" width="235" height="24" alt="">
            <img src="assets/images/<?= $logoWhite ?>" class="hidden dark:inline-block" width="235" height="24" alt="">
        </a>

        <!-- End Logo container-->
        <div class="menu-extras">
            <div class="menu-item">
                <!-- Mobile menu toggle-->
                <a class="navbar-toggle" id="isToggle" onclick="toggleMenu()">
                    <div class="lines">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </a>
                <!-- End mobile menu toggle-->
            </div>
        </div>

        <div id="navigation">
            <!-- Navigation Menu-->
            <ul class="navigation-menu">
                <li><a href="index.php<?= $linkCompleteHref ?>" class="sub-menu-item">Home</a></li>
                <li><a href="aboutus.php<?= $linkCompleteHref ?>" class="sub-menu-item">About Us</a></li>
                <li><a href="job-grid.php<?= $linkCompleteHref ?>" class="sub-menu-item">Job Grid</a></li>
                <li><a href="subscribe.php<?= $linkCompleteHref ?>" class="sub-menu-item">Subscribe</a></li>
                <li><a href="partners.php<?= $linkCompleteHref ?>" class="sub-menu-item">Partners</a></li>
                <li><a href="contact.php<?= $linkCompleteHref ?>" class="sub-menu-item">Contact</a></li>
            </ul><!--end navigation menu-->
        </div><!--end navigation-->
    </div><!--end container-->
</nav><!--end header-->
<!-- End Navbar -->