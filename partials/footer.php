<!-- Footer Start -->
<footer class="relative bg-slate-900 dark:bg-slate-800 text-gray-200 dark:text-gray-200">
    <div class="container relative text-center">
        <div class="grid grid-cols-1">
            <div class="py-7.5 px-0">
                <div class="grid md:grid-cols-2 items-center">
                    <div class="md:text-start text-center">
                        <a href="#" class="text-[22px] focus:outline-none">
                            <img src="assets/images/<?= $logoWhite ?>" class="mx-auto md:me-auto md:ms-0" width="235" height="24" alt="">
                        </a>
                    </div>

                    <ul class="list-none footer-list md:text-end text-center mt-6 md:mt-0">
                        <li class="inline"><i class="ri-circle-fill text-[6px] align-middle"></i> <a href="privacy.php<?= $linkCompleteHref ?>" class="text-gray-300 hover:text-gray-400 duration-500 ease-in-out">Privacy Policy</a></li>
                        <li class="inline ms-2"><i class="ri-circle-fill text-[6px] align-middle"></i> <a href="terms.php<?= $linkCompleteHref ?>" class="text-gray-300 hover:text-gray-400 duration-500 ease-in-out">Terms of Use</a></li>
                        <li class="inline ms-2"><i class="ri-circle-fill text-[6px] align-middle"></i> <a href="contact.php<?= $linkCompleteHref ?>" class="text-gray-300 hover:text-gray-400 duration-500 ease-in-out">Contact</a></li>
                    </ul><!--end icon-->
                </div><!--end grid-->
            </div>
        </div>
    </div><!--end container-->

    <div class="py-7.5 px-0 border-t border-gray-800 dark:border-gray-700">
        <div class="container relative text-center">
            <div class="grid grid-cols-1">
                <div class="text-center">
                    <p class="mb-0">© <script>
                            document.write(new Date().getFullYear())
                        </script> Design with <i class="ri-heart-fill text-red-600"></i> by <a href="https://shreethemes.in/" target="_blank" class="text-reset"><?= $siteTitle ?></a>.</p>
                </div>
            </div><!--end grid-->
        </div><!--end container-->
    </div>
</footer><!--end footer-->
<!-- Footer End -->

<?php
// UI global: cookie, back-to-top, switcher, etc.
include __DIR__ . '/global-ui.php';

// Scripts globais
include __DIR__ . '/scripts.php';
?>