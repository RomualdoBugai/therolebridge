<?php 
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php'; 
?>

<!DOCTYPE html>
<html lang="en" class="light scroll-smooth" dir="ltr">

<?php include __DIR__ . '/partials/head.php'; ?>

<body class="font-nunito text-base text-slate-900 dark:text-white dark:bg-slate-900">

    <?php include __DIR__ . '/partials/navbar.php'; ?>

    <!-- Start Hero -->
    <section class="relative table w-full py-32 lg:py-40 bg-gray-50 dark:bg-slate-800">
        <div class="container relative">
            <div class="grid grid-cols-1 text-center mt-10">
                <h3 class="text-3xl leading-normal font-semibold">Thanks for subscribing!</h3>
                <p class="text-slate-400 mt-2 text-sm">
                    Check your inbox in the next minutes. We’ll start sending you daily curated U.S. job leads
                    based on your preferences.
                </p>
            </div><!--end grid-->
        </div><!--end container-->

        <div class="absolute text-center z-10 bottom-5 start-0 end-0 mx-3">
            <ul class="tracking-[0.5px] mb-0 inline-block">
                <li class="inline-block uppercase text-13 font-bold duration-500 ease-in-out hover:text-primary">
                    <a href="index.php">Home</a>
                </li>
                <li class="inline-block text-base text-slate-950 dark:text-white mx-0.5 ltr:rotate-0 rtl:rotate-180">
                    <i class="ri-arrow-right-s-line"></i>
                </li>
                <li class="inline-block uppercase text-13 font-bold text-primary" aria-current="page">Thanks</li>
            </ul>
        </div>
    </section><!--end section-->
    <div class="relative">
        <div class="shape absolute sm:-bottom-px -bottom-0.5 start-0 end-0 overflow-hidden z-1 text-white dark:text-slate-900">
            <svg class="w-full h-auto scale-[2.0] origin-top" viewBox="0 0 2880 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0 48H1437.5H2880V0H2160C1442.5 52 720 0 720 0H0V48Z" fill="currentColor"></path>
            </svg>
        </div>
    </div>
    <!-- End Hero -->

    <?php include __DIR__ . '/partials/footer.php'; ?>
</body>

</html>