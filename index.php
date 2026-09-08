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
    <section class="md:h-screen py-36 h-auto relative flex items-center background-effect overflow-hidden bg-[url('../../assets/images/job/job.jpg')] bg-cover">
        <div class="container-fluid">
            <div class="absolute inset-0 z-0 bg-[url('../../assets/images/job/curve-shape.png')] dark:bg-[url('../../assets/images/job/curve-shape-dark.png')] bg-cover"></div>
        </div><!--end container-->

        <div class="container relative z-1">
            <div class="grid grid-cols-1 mt-10">
                <h4 class="lg:leading-normal leading-normal text-4xl lg:text-5xl mb-5 font-bold">
                    Get Daily Job Leads <br>
                    Straight to Your <span class="text-primary">Inbox</span>
                </h4>

                <p class="text-slate-400 text-lg max-w-xl">
                    Discover fresh job opportunities in the U.S. every day. We track hundreds of hiring partners and send you curated job leads that match what you're looking for.
                </p>

                <!-- NOVO: deixar claro que você não é empregador / recruiter -->
                <p class="text-slate-400 text-sm max-w-xl mt-3">
                    We're not a recruiter or employer. We curate job leads from multiple job sources and send them straight to your inbox.
                </p>

                <div class="grid lg:grid-cols-12 grid-cols-1" id="reserve-form">
                    <div class="lg:col-span-10 mt-8">
                        <div class="bg-white dark:bg-slate-900 border-0 shadow-sm rounded p-3">
                            <?php include __DIR__ . '/job-form.php'; ?>
                        </div>
                    </div><!--ed col-->
                </div><!--end grid-->

                <div class="mt-6">
                    <span class="text-slate-400">
                        <span class="text-slate-900 dark:text-white">Popular alerts:</span>
                        software engineer · remote jobs · customer service · warehouse · no experience · part-time
                    </span>
                </div>
            </div><!--end grid-->
        </div><!--end container-->

        <div class="absolute inset-0 bg-primary/5"></div>
        <ul class="circles absolute inset-0 h-full w-full overflow-hidden p-0 mb-0">
            <li class="brand-img"><img src="assets/images/client/shree-logo.png" class="size-9" alt="Brand logo"></li>
            <li class="brand-img"><img src="assets/images/client/skype.png" class="size-9" alt="Brand logo"></li>
            <li class="brand-img"><img src="assets/images/client/snapchat.png" class="size-9" alt="Brand logo"></li>
            <li class="brand-img"><img src="assets/images/client/spotify.png" class="size-9" alt="Brand logo"></li>
            <li class="brand-img"><img src="assets/images/client/telegram.png" class="size-9" alt="Brand logo"></li>
            <li class="brand-img"><img src="assets/images/client/whatsapp.png" class="size-9" alt="Brand logo"></li>
            <li class="brand-img"><img src="assets/images/client/android.png" class="size-9" alt="Brand logo"></li>
            <li class="brand-img"><img src="assets/images/client/facebook-logo-2019.png" class="size-9" alt="Brand logo"></li>
            <li class="brand-img"><img src="assets/images/client/linkedin.png" class="size-9" alt="Brand logo"></li>
            <li class="brand-img"><img src="assets/images/client/google-logo.png" class="size-9" alt="Brand logo"></li>
        </ul>
    </section><!--end section-->
    <div class="relative">
        <div class="absolute block w-full h-auto bottom-6.25 z-1 start-0">
            <a href=""><i class="ri-arrow-down-line absolute top-0 start-0 end-0 text-center inline-flex items-center justify-center rounded-full bg-white dark:bg-slate-900 size-12 mx-auto shadow-md dark:shadow-gray-800"></i></a>
        </div>

        <div class="shape absolute sm:-bottom-px -bottom-0.5 start-0 end-0 overflow-hidden text-white dark:text-slate-900">
            <svg class="w-full h-auto scale-[2.0] origin-top" viewBox="0 0 2880 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0 48H1437.5H2880V0H2160C1442.5 52 720 0 720 0H0V48Z" fill="currentColor"></path>
            </svg>
        </div>
    </div>
    <!-- End Hero -->

    <!-- NOVO: How it works -->
    <section class="relative md:py-16 py-12 bg-white dark:bg-slate-900">
        <div class="container relative">
            <div class="grid grid-cols-1 text-center mb-10">
                <h3 class="mb-4 md:text-3xl text-2xl leading-normal font-semibold">
                    How <?= $siteTitle ?> works
                </h3>
                <p class="text-slate-400 max-w-2xl mx-auto">
                    We keep the process simple: tell us what you want, and we do the heavy lifting in the background.
                </p>
            </div>

            <div class="grid md:grid-cols-3 grid-cols-1 gap-6">
                <div class="text-center bg-gray-50 dark:bg-slate-800 rounded-md p-6 shadow-sm dark:shadow-gray-800">
                    <div class="mx-auto mb-4 size-12 rounded-full bg-primary/10 flex items-center justify-center">
                        <span class="font-semibold text-primary">1</span>
                    </div>
                    <h5 class="font-semibold mb-2">You tell us what you want</h5>
                    <p class="text-slate-400 text-sm">
                        Share your job keyword and location (for example: "warehouse", "remote", or "customer service in SC").
                    </p>
                </div>

                <div class="text-center bg-gray-50 dark:bg-slate-800 rounded-md p-6 shadow-sm dark:shadow-gray-800">
                    <div class="mx-auto mb-4 size-12 rounded-full bg-primary/10 flex items-center justify-center">
                        <span class="font-semibold text-primary">2</span>
                    </div>
                    <h5 class="font-semibold mb-2">We collect and filter opportunities</h5>
                    <p class="text-slate-400 text-sm">
                        We monitor multiple job sources and pick relevant roles that match your profile, in the U.S. only.
                    </p>
                </div>

                <div class="text-center bg-gray-50 dark:bg-slate-800 rounded-md p-6 shadow-sm dark:shadow-gray-800">
                    <div class="mx-auto mb-4 size-12 rounded-full bg-primary/10 flex items-center justify-center">
                        <span class="font-semibold text-primary">3</span>
                    </div>
                    <h5 class="font-semibold mb-2">You get a clean daily email</h5>
                    <p class="text-slate-400 text-sm">
                        Once a day, we send 5–10 curated job leads straight to your inbox with direct links to apply on partner sites.
                    </p>
                </div>
            </div>
        </div>
    </section>
    <!-- End How it works -->

    <!-- Start Section-->
    <section class="relative md:py-24 py-16">
        <div class="container relative">
            <div class="grid md:grid-cols-12 grid-cols-1 pb-8 items-end">
                <div class="lg:col-span-8 md:col-span-6 md:text-start text-center">
                    <h3 class="mb-4 md:text-3xl md:leading-normal text-2xl leading-normal font-semibold">Browse Jobs by Category</h3>
                    <p class="text-slate-400 max-w-xl">
                        Quickly explore the most in-demand categories and find opportunities that match your skills.
                    </p>
                </div>

                <div class="lg:col-span-4 md:col-span-6 md:text-end hidden md:block">
                    <a href="job-grid.php" class="relative inline-block font-semibold tracking-wide align-middle text-base text-center border-none after:content-[''] after:absolute after:h-px after:w-0 hover:after:w-full after:end-0 hover:after:end-auto after:bottom-0 after:start-0 after:duration-500 text-slate-400 hover:text-primary after:bg-primary duration-500 ease-in-out">
                        All Categories <i class="ri-arrow-right-line align-middle"></i>
                    </a>
                </div>
            </div><!--end grid-->
        </div><!--end container-->

        <div class="container relative">
            <div class="grid grid-cols-1 relative">
                <div class="tiny-five-item">
                    <div class="tiny-slide">
                        <div class="px-3 py-10 rounded-md shadow-sm dark:shadow-gray-800 group text-center bg-white dark:bg-slate-900 hover:bg-primary/5 dark:hover:bg-primary/5 duration-500 m-2">
                            <div class="size-21 bg-primary/5 group-hover:bg-primary text-primary group-hover:text-white rounded-full text-3xl flex align-middle justify-center items-center shadow-xs dark:shadow-gray-800 duration-500 mx-auto">
                                <i class="ri-gitlab-line"></i>
                            </div>

                            <div class="content mt-6">
                                <a href="job-grid.php" class="title h5 text-lg font-medium hover:text-primary">
                                    Business <br> Development
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="tiny-slide">
                        <div class="px-3 py-10 rounded-md shadow-sm dark:shadow-gray-800 group text-center bg-white dark:bg-slate-900 hover:bg-primary/5 dark:hover:bg-primary/5 duration-500 m-2">
                            <div class="size-21 bg-primary/5 group-hover:bg-primary text-primary group-hover:text-white rounded-full text-3xl flex align-middle justify-center items-center shadow-xs dark:shadow-gray-800 duration-500 mx-auto">
                                <i class="ri-book-open-line"></i>
                            </div>

                            <div class="content mt-6">
                                <a href="job-grid.php" class="title h5 text-lg font-medium hover:text-primary">
                                    Marketing & <br> Communication
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="tiny-slide">
                        <div class="px-3 py-10 rounded-md shadow-sm dark:shadow-gray-800 group text-center bg-white dark:bg-slate-900 hover:bg-primary/5 dark:hover:bg-primary/5 duration-500 m-2">
                            <div class="size-21 bg-primary/5 group-hover:bg-primary text-primary group-hover:text-white rounded-full text-3xl flex align-middle justify-center items-center shadow-xs dark:shadow-gray-800 duration-500 mx-auto">
                                <i class="ri-pie-chart-line"></i>
                            </div>

                            <div class="content mt-6">
                                <a href="job-grid.php" class="title h5 text-lg font-medium hover:text-primary">
                                    Project <br> Management
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="tiny-slide">
                        <div class="px-3 py-10 rounded-md shadow-sm dark:shadow-gray-800 group text-center bg-white dark:bg-slate-900 hover:bg-primary/5 dark:hover:bg-primary/5 duration-500 m-2">
                            <div class="size-21 bg-primary/5 group-hover:bg-primary text-primary group-hover:text-white rounded-full text-3xl flex align-middle justify-center items-center shadow-xs dark:shadow-gray-800 duration-500 mx-auto">
                                <i class="ri-feedback-line"></i>
                            </div>

                            <div class="content mt-6">
                                <a href="job-grid.php" class="title h5 text-lg font-medium hover:text-primary">
                                    Customer <br> Service
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="tiny-slide">
                        <div class="px-3 py-10 rounded-md shadow-sm dark:shadow-gray-800 group text-center bg-white dark:bg-slate-900 hover:bg-primary/5 dark:hover:bg-primary/5 duration-500 m-2">
                            <div class="size-21 bg-primary/5 group-hover:bg-primary text-primary group-hover:text-white rounded-full text-3xl flex align-middle justify-center items-center shadow-xs dark:shadow-gray-800 duration-500 mx-auto">
                                <i class="ri-layout-line"></i>
                            </div>

                            <div class="content mt-6">
                                <a href="job-grid.php" class="title h5 text-lg font-medium hover:text-primary">
                                    Software <br> Engineering
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="tiny-slide">
                        <div class="px-3 py-10 rounded-md shadow-sm dark:shadow-gray-800 group text-center bg-white dark:bg-slate-900 hover:bg-primary/5 dark:hover:bg-primary/5 duration-500 m-2">
                            <div class="size-21 bg-primary/5 group-hover:bg-primary text-primary group-hover:text-white rounded-full text-3xl flex align-middle justify-center items-center shadow-xs dark:shadow-gray-800 duration-500 mx-auto">
                                <i class="ri-fire-line"></i>
                            </div>

                            <div class="content mt-6">
                                <a href="job-grid.php" class="title h5 text-lg font-medium hover:text-primary">
                                    Human <br> Resources
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div><!--grid-->

            <div class="grid md:grid-cols-12 grid-cols-1 md:hidden mt-8">
                <div class="md:col-span-12 text-center">
                    <a href="job-grid.php" class="relative inline-block font-semibold tracking-wide align-middle text-base text-center border-none after:content-[''] after:absolute after:h-px after:w-0 hover:after:w-full after:end-0 hover:after:end-auto after:bottom-0 after:start-0 after:duration-500 text-slate-400 hover:text-primary after:bg-primary duration-500 ease-in-out">
                        All Categories <i class="ri-arrow-right-line align-middle"></i>
                    </a>
                </div>
            </div><!--end grid-->
        </div><!--end container-->
    </section><!--end section-->
    <!-- End Section-->

    <!-- Start -->
    <section class="relative md:py-24 py-16 bg-gray-50 dark:bg-slate-800">
        <div class="container relative">
            <div class="grid md:grid-cols-12 grid-cols-1 items-center gap-7.5">
                <div class="lg:col-span-5 md:col-span-6">
                    <div class="grid grid-cols-12 gap-6 items-center">
                        <div class="col-span-6">
                            <div class="grid grid-cols-1 gap-6">
                                <img src="assets/images/about/ab03.jpg" class="shadow-sm rounded-md" alt="Job search on laptop">
                                <img src="assets/images/about/ab02.jpg" class="shadow-sm rounded-md" alt="Happy candidate after getting hired">
                            </div>
                        </div>

                        <div class="col-span-6">
                            <div class="grid grid-cols-1 gap-6">
                                <img src="assets/images/about/ab01.jpg" class="shadow-sm rounded-md" alt="Team collaborating in office">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-7 md:col-span-6">
                    <div class="lg:ms-5">
                        <h3 class="mb-6 md:text-3xl text-2xl lg:leading-normal leading-normal font-medium">
                            Your Shortcut to the Best Jobs Online
                        </h3>

                        <p class="text-slate-400 max-w-xl mb-2">
                            <?= $siteTitle ?> finds and organizes job postings across multiple sources, then delivers the
                            most relevant opportunities directly to your inbox – so you spend less time searching and more
                            time applying.
                        </p>
                        <p class="text-slate-400 max-w-xl">
                            Tell us what kind of job you want, where you want to work, and how flexible you need it to be.
                            Our system tracks new openings daily and surfaces roles that match your profile, including
                            remote, entry-level, and no-experience-required positions.
                        </p>

                        <div class="mt-6">
                            <a href="subscribe.php<?= $linkCompleteHref ?>" class="relative inline-block font-semibold tracking-wide align-middle text-base text-center border-none after:content-[''] after:absolute after:h-px after:w-0 hover:after:w-full after:end-0 hover:after:end-auto after:bottom-0 after:start-0 after:duration-500 text-primary hover:text-primary after:bg-primary duration-500 ease-in-out">
                                Start Receiving Job Leads <i class="ri-arrow-right-line align-middle"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div><!--end grid-->
        </div><!--end container-->

        <!-- Early-stage / roadmap block já ajustado antes -->
        <div class="container relative mt-12">
            <div class="grid md:grid-cols-12 grid-cols-1 gap-8 items-start">
                <div class="md:col-span-5 mr-2">
                    <h4 class="font-semibold text-2xl mb-4">
                        Early-stage project, built in public
                    </h4>
                    <p class="text-slate-400">
                        <?= $siteTitle ?> is an early-stage project – currently testing with our first users and improving
                        the experience based on real feedback. No made-up vanity numbers, just a clear focus on building
                        something useful for job seekers in the U.S.
                    </p>
                </div>

                <div class="md:col-span-7">
                    <h5 class="font-semibold text-lg mb-3">
                        What we're working on next
                    </h5>
                    <div class="grid sm:grid-cols-2 grid-cols-1 gap-4">
                        <div class="p-4 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800">
                            <h6 class="font-semibold mb-1">More job sources</h6>
                            <p class="text-slate-400 text-sm">
                                Expanding integrations with job boards and hiring partners to increase the number of daily leads.
                            </p>
                        </div>
                        <div class="p-4 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800">
                            <h6 class="font-semibold mb-1">Smarter matching</h6>
                            <p class="text-slate-400 text-sm">
                                Refining our matching logic so the jobs you receive are closer to your skills, location, and goals.
                            </p>
                        </div>
                        <div class="p-4 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800">
                            <h6 class="font-semibold mb-1">Better email experience</h6>
                            <p class="text-slate-400 text-sm">
                                Testing different templates and frequencies to keep emails clear, scannable, and actually helpful.
                            </p>
                        </div>
                        <div class="p-4 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800">
                            <h6 class="font-semibold mb-1">User dashboard (planned)</h6>
                            <p class="text-slate-400 text-sm">
                                Future area where you'll be able to edit preferences, pause emails, and track saved jobs in one place.
                            </p>
                        </div>
                    </div>
                </div>
            </div><!--end grid-->
        </div><!--end container-->
    </section><!--end section-->
    <!-- End -->

    <?php include __DIR__ . '/partials/footer.php'; ?>

</body>
</html>
