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
    <section class="relative table w-full py-36 lg:py-44 bg-[url('../../assets/images/company/aboutus.jpg')] bg-no-repeat bg-center bg-cover">
        <div class="absolute inset-0 bg-slate-900 opacity-80"></div>

        <div class="container relative">
            <div class="grid grid-cols-1 pb-8 text-center mt-10">
                <h1 class="mb-4 md:text-4xl text-3xl md:leading-normal leading-normal font-semibold text-white">
                    About <?= $siteTitle ?>
                </h1>

                <p class="text-slate-200 text-lg max-w-2xl mx-auto">
                    We help job seekers in the U.S. receive <span class="font-semibold">daily, targeted job leads</span>
                    straight to their inbox – so they spend less time searching and more time actually applying.
                </p>
            </div>
        </div>

        <div class="absolute text-center z-10 bottom-5 start-0 end-0 mx-3">
            <ul class="tracking-[0.5px] mb-0 inline-block">
                <li class="inline-block uppercase text-13 font-bold duration-500 ease-in-out text-white/60 hover:text-white">
                    <a href="index.php<?= $linkCompleteHref ?>">Home</a>
                </li>
                <li class="inline-block text-base text-white/60 mx-0.5 ltr:rotate-0 rtl:rotate-180">
                    <i class="ri-arrow-right-s-line"></i>
                </li>
                <li class="inline-block uppercase text-13 font-bold duration-500 ease-in-out text-white" aria-current="page">
                    About Us
                </li>
            </ul>
        </div>
    </section>
    <div class="relative">
        <div class="shape absolute sm:-bottom-px -bottom-0.5 start-0 end-0 overflow-hidden z-1 text-white dark:text-slate-900">
            <svg class="w-full h-auto scale-[2.0] origin-top" viewBox="0 0 2880 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0 48H1437.5H2880V0H2160C1442.5 52 720 0 720 0H0V48Z" fill="currentColor"></path>
            </svg>
        </div>
    </div>
    <!-- End Hero -->

    <!-- Our Story -->
    <section class="relative md:py-24 py-16">
        <div class="container relative">
            <div class="grid md:grid-cols-12 grid-cols-1 items-center gap-7.5">
                <!-- Images -->
                <div class="lg:col-span-5 md:col-span-6">
                    <div class="grid grid-cols-12 gap-6 items-center">
                        <div class="col-span-6">
                            <div class="grid grid-cols-1 gap-6">
                                <img src="assets/images/about/ab03.jpg" class="shadow-sm rounded-md" alt="Job search illustration 1">
                                <img src="assets/images/about/ab02.jpg" class="shadow-sm rounded-md" alt="Job search illustration 2">
                            </div>
                        </div>
                        <div class="col-span-6">
                            <div class="grid grid-cols-1 gap-6">
                                <img src="assets/images/about/ab01.jpg" class="shadow-sm rounded-md" alt="Job search illustration 3">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Text -->
                <div class="lg:col-span-7 md:col-span-6">
                    <div class="lg:ms-5">
                        <p class="uppercase tracking-[0.25em] text-xs text-primary font-semibold mb-2">
                            Our Story
                        </p>

                        <h2 class="mb-4 md:text-3xl text-2xl md:leading-normal leading-normal font-semibold">
                            Built for people who are tired of endless job board scrolling
                        </h2>

                        <p class="text-slate-500 max-w-xl">
                            <?= $siteTitle ?> was created with a simple idea: most job seekers don’t need another
                            complex platform — they need <span class="font-semibold text-slate-700">relevant job leads delivered to them daily</span>
                            in a format they can actually use.
                        </p>

                        <p class="text-slate-500 max-w-xl mt-4">
                            We connect with multiple job sources, apply smart filters based on your profile,
                            and send you a clean, easy-to-skim daily email with the best opportunities for that day.
                            No clutter, no fluff, no generic spam blasts.
                        </p>

                        <div class="grid grid-cols-2 gap-4 mt-6">
                            <div>
                                <div class="flex items-baseline">
                                    <span class="text-primary text-3xl font-bold">
                                        <span class="counter-value" data-target="3">3</span>+
                                    </span>
                                    <span class="ms-2 text-slate-600 text-sm">
                                        daily email campaigns running<br>for testing & optimization
                                    </span>
                                </div>
                            </div>
                            <div>
                                <div class="flex items-baseline">
                                    <span class="text-primary text-3xl font-bold">
                                        <span class="counter-value" data-target="10000">10k</span>+
                                    </span>
                                    <span class="ms-2 text-slate-600 text-sm">
                                        job leads processed per month<br>(and growing)
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- CTAs alinhadas: seeker + partner -->
                        <div class="mt-6 flex flex-wrap gap-3">
                            <a href="subscribe.php<?= $linkCompleteHref ?>" class="py-2.5 px-5 inline-block font-semibold tracking-wide align-middle duration-500 text-base text-center bg-primary text-white rounded-md hover:bg-primary-700">
                                Get Daily Job Leads
                            </a>

                            <a href="partners.php<?= $linkCompleteHref ?>" class="py-2.5 px-5 inline-block font-semibold tracking-wide align-middle duration-500 text-base text-center border border-slate-200 hover:border-primary hover:text-primary rounded-md text-slate-700">
                                Become a Partner
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- End Our Story -->

    <!-- Our Mission -->
    <section class="relative md:py-20 py-14 bg-gray-50 dark:bg-slate-900">
        <div class="container relative">
            <div class="grid md:grid-cols-2 grid-cols-1 gap-7.5 items-center">
                <div>
                    <p class="uppercase tracking-[0.25em] text-xs text-primary font-semibold mb-2 mt-10">
                        Our Mission
                    </p>
                    <h2 class="mb-4 md:text-3xl text-2xl md:leading-normal leading-normal font-semibold">
                        Make job search less overwhelming and more intentional
                    </h2>
                    <p class="text-slate-500 mb-4 max-w-xl">
                        Our mission is to help people in the U.S. stop wasting hours lost in job boards and instead
                        receive <span class="font-semibold text-slate-700">actionable, relevant job leads</span> in their inbox — while respecting
                        their time, inbox, and privacy.
                    </p>
                    <p class="text-slate-500 max-w-xl">
                        We focus on <span class="font-semibold text-slate-700">real opportunities</span>, transparent communication, and performance for
                        our partners. No fake promises, no spammy tricks, and no dark patterns.
                    </p>
                </div>

                <div>
                    <div class="grid sm:grid-cols-2 grid-cols-1 gap-4">
                        <div class="p-4 rounded-md bg-white dark:bg-slate-800 shadow-sm dark:shadow-gray-800">
                            <h3 class="text-sm font-semibold mb-1">Job-seeker first</h3>
                            <p class="text-slate-500 text-xs">
                                We only send campaigns that we’d be comfortable receiving ourselves: clear, useful,
                                and easy to unsubscribe from.
                            </p>
                        </div>
                        <div class="p-4 rounded-md bg-white dark:bg-slate-800 shadow-sm dark:shadow-gray-800">
                            <h3 class="text-sm font-semibold mb-1">Quality over volume</h3>
                            <p class="text-slate-500 text-xs">
                                We prefer fewer, better daily opportunities over blasting every possible link just to increase clicks.
                            </p>
                        </div>
                        <div class="p-4 rounded-md bg-white dark:bg-slate-800 shadow-sm dark:shadow-gray-800">
                            <h3 class="text-sm font-semibold mb-1">Data-driven testing</h3>
                            <p class="text-slate-500 text-xs">
                                We constantly test subject lines, templates, and segments to improve engagement without
                                burning out the list.
                            </p>
                        </div>
                        <div class="p-4 rounded-md bg-white dark:bg-slate-800 shadow-sm dark:shadow-gray-800">
                            <h3 class="text-sm font-semibold mb-1">Ethical partnerships</h3>
                            <p class="text-slate-500 text-xs">
                                We work with partners who care about real candidates and long-term results, not just raw traffic numbers.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- End Our Mission -->

    <!-- How It Works -->
    <section class="relative md:py-24 py-16 bg-gray-50 dark:bg-slate-800">
        <div class="container relative">
            <div class="grid grid-cols-1 pb-8 text-center">
                <h2 class="mb-4 md:text-3xl text-2xl md:leading-normal leading-normal font-semibold">
                    How <?= $siteTitle ?> Works
                </h2>
                <p class="text-slate-400 max-w-2xl mx-auto">
                    Instead of you chasing jobs across multiple platforms, we bring a curated list of job leads to you every day.
                </p>
            </div>

            <div class="grid lg:grid-cols-3 md:grid-cols-3 grid-cols-1 gap-7.5 mt-8">
                <div class="p-6 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800 hover:shadow-md dark:hover:shadow-gray-700 duration-500">
                    <span class="inline-flex items-center justify-center h-11.25 w-11.25 rounded-full bg-primary/10 text-primary mb-4">
                        <i class="ri-user-search-line text-xl"></i>
                    </span>
                    <h3 class="text-lg font-semibold mb-2">1. You tell us what you want</h3>
                    <p class="text-slate-500 text-sm">
                        City, state, keyword, type of role – we use these signals to shape your daily job lead feed.
                    </p>
                </div>

                <div class="p-6 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800 hover:shadow-md dark:hover:shadow-gray-700 duration-500">
                    <span class="inline-flex items-center justify-center h-11.25 w-11.25 rounded-full bg-primary/10 text-primary mb-4">
                        <i class="ri-database-2-line text-xl"></i>
                    </span>
                    <h3 class="text-lg font-semibold mb-2">2. We collect and filter opportunities</h3>
                    <p class="text-slate-500 text-sm">
                        We integrate with job partners and apply filters to remove noise, duplicates and irrelevant roles, keeping only the best daily options.
                    </p>
                </div>

                <div class="p-6 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800 hover:shadow-md dark:hover:shadow-gray-700 duration-500">
                    <span class="inline-flex items-center justify-center h-11.25 w-11.25 rounded-full bg-primary/10 text-primary mb-4">
                        <i class="ri-mail-send-line text-xl"></i>
                    </span>
                    <h3 class="text-lg font-semibold mb-2">3. You get a clean daily email</h3>
                    <p class="text-slate-500 text-sm">
                        A single daily email with links to apply directly. No login required, unsubscribe anytime.
                    </p>
                </div>
            </div>
        </div>
    </section>
    <!-- End How It Works -->

    <!-- Who We Serve -->
    <section class="relative md:py-24 py-16">
        <div class="container relative">
            <div class="grid md:grid-cols-2 grid-cols-1 gap-7.5 items-start">
                <div>
                    <h2 class="mb-4 md:text-3xl text-2xl md:leading-normal leading-normal font-semibold">
                        For Job Seekers
                    </h2>
                    <p class="text-slate-500 mb-4">
                        Whether you’re actively looking or just keeping an eye on the market, <?= $siteTitle ?> is built
                        to <span class="font-semibold text-slate-700">reduce friction in your search</span>.
                    </p>
                    <ul class="list-none space-y-3 text-slate-500 text-sm">
                        <li class="flex items-start">
                            <i class="ri-check-line text-primary mt-0.5 me-2"></i>
                            <span>One daily email with curated job leads instead of dozens of scattered alerts.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="ri-check-line text-primary mt-0.5 me-2"></i>
                            <span>Focus on U.S. opportunities aligned with your location preferences.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="ri-check-line text-primary mt-0.5 me-2"></i>
                            <span>Easy-to-skim format so you can apply in minutes, not hours.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="ri-check-line text-primary mt-0.5 me-2"></i>
                            <span>Transparent: no hidden contracts, unsubscribe with one click.</span>
                        </li>
                    </ul>

                    <a href="subscribe.php<?= $linkCompleteHref ?>" class="inline-flex items-center mt-5 text-primary font-semibold text-sm hover:underline">
                        Get started with daily job leads
                        <i class="ri-arrow-right-line ms-1 text-base"></i>
                    </a>
                </div>

                <div>
                    <h2 class="mb-4 md:text-3xl text-2xl md:leading-normal leading-normal font-semibold">
                        For Partners & Job Networks
                    </h2>
                    <p class="text-slate-500 mb-4">
                        We work with selected partners to drive qualified, high-intent clicks to their openings through
                        <span class="font-semibold text-slate-700">curated daily email distribution</span>.
                    </p>
                    <ul class="list-none space-y-3 text-slate-500 text-sm">
                        <li class="flex items-start">
                            <i class="ri-check-line text-primary mt-0.5 me-2"></i>
                            <span>Performance-focused traffic (CPC-based monetization where applicable).</span>
                        </li>
                        <li class="flex items-start">
                            <i class="ri-check-line text-primary mt-0.5 me-2"></i>
                            <span>Audience segmentation by location and intent signals.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="ri-check-line text-primary mt-0.5 me-2"></i>
                            <span>Continuous testing of subject lines, templates and send times.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="ri-check-line text-primary mt-0.5 me-2"></i>
                            <span>Deliverability-first mindset to protect long-term performance.</span>
                        </li>
                    </ul>

                    <a href="partners.php<?= $linkCompleteHref ?>" class="inline-flex items-center mt-5 text-primary font-semibold text-sm hover:underline">
                        Become a partner
                        <i class="ri-arrow-right-line ms-1 text-base"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>
    <!-- End Who We Serve -->

    <!-- CTA -->
    <section class="relative md:py-20 md:p-20 py-14 bg-linear-to-r from-primary-500 to-primary">
        <div class="container relative">
            <div class="grid md:grid-cols-2 grid-cols-1 items-center gap-7.5">
                <div class="text-white">
                    <h2 class="md:text-3xl text-2xl font-semibold mb-3">
                        Ready to stop hunting and start receiving daily job leads?
                    </h2>
                    <p class="text-white/80 max-w-xl">
                        Join our email list and start receiving a curated set of opportunities that match your profile.
                        You can unsubscribe at any time if it’s not helpful.
                    </p>
                </div>
                <div class="md:text-end text-start">
                    <a href="subscribe.php<?= $linkCompleteHref ?>" class="py-3 px-6 inline-block font-semibold tracking-wide align-middle duration-500 text-base text-center bg-white text-primary rounded-md hover:bg-slate-100">
                        Get Daily Job Leads
                    </a>
                </div>
            </div>
        </div>
    </section>
    <!-- End CTA -->

    <!-- Testimonials -->
    <section class="relative md:py-24 py-16 bg-gray-50 dark:bg-slate-800">
        <div class="container relative">
            <div class="grid grid-cols-1 pb-8 text-center">
                <h2 class="mb-4 md:text-3xl text-2xl md:leading-normal leading-normal font-semibold">
                    What our users say
                </h2>
                <p class="text-slate-400 max-w-2xl mx-auto">
                    These are early impressions from people using <?= $siteTitle ?> to simplify their job search.
                </p>
            </div>

            <div class="grid md:grid-cols-3 grid-cols-1 gap-7.5 mt-8">
                <div class="p-6 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800">
                    <p class="text-slate-500 text-sm">
                        "In the first week using <?= $siteTitle ?>, I applied to more relevant roles than in the whole previous month."
                    </p>
                    <h6 class="text-primary font-semibold mt-4 text-sm">Thomas – Warehouse Associate</h6>
                </div>
                <div class="p-6 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800">
                    <p class="text-slate-500 text-sm">
                        "I love that everything is in one email. I check it with my coffee, pick a few jobs, and apply."
                    </p>
                    <h6 class="text-primary font-semibold mt-4 text-sm">Carla – Customer Support</h6>
                </div>
                <div class="p-6 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800">
                    <p class="text-slate-500 text-sm">
                        "It feels like someone is pre-filtering the noise for me. That alone saves a ton of time."
                    </p>
                    <h6 class="text-primary font-semibold mt-4 text-sm">Roberto – Driver</h6>
                </div>
            </div>
        </div>
    </section>
    <!-- End Testimonials -->

    <?php include __DIR__ . '/partials/footer.php'; ?>

</body>

</html>