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
    <section class="relative table w-full py-36 bg-[url('../../assets/images/helpcenter.jpg')] bg-center bg-no-repeat bg-cover">
        <div class="absolute inset-0 bg-slate-900 opacity-80"></div>
        <div class="container relative">
            <div class="grid grid-cols-1 pb-8 text-center mt-10">
                <h3 class="md:text-4xl text-3xl md:leading-normal tracking-wide leading-normal font-medium text-white">
                    Submit Your Subscription Request
                </h3>
                <p class="mt-4 max-w-2xl mx-auto text-slate-200">
                    Tell us what kind of job you want and where you want to work. We’ll send you a daily email with hand-picked opportunities.
                </p>

                <!-- Botão para rolar até o formulário -->
                <div class="mt-6 flex justify-center">
                    <a href="#subscribe-form-section"
                       class="inline-flex items-center justify-center px-5 py-2.5 rounded-md text-sm font-semibold bg-primary hover:bg-primary-700 text-white transition">
                        Go to subscription form
                        <i class="ri-arrow-down-line ms-2 text-[16px]"></i>
                    </a>
                </div>
            </div><!--end grid-->
        </div><!--end container-->

        <div class="absolute text-center z-10 bottom-5 start-0 end-0 mx-3">
            <ul class="tracking-[0.5px] mb-0 inline-block">
                <li class="inline-block uppercase text-13 font-bold duration-500 ease-in-out text-white/50 hover:text-white">
                    <a href="index.php<?= $linkCompleteHref ?>">
                        Home
                    </a>
                </li>
                <li class="inline-block text-base text-white/50 mx-0.5 ltr:rotate-0 rtl:rotate-180">
                    <i class="ri-arrow-right-s-line"></i>
                </li>
                <li class="inline-block uppercase text-13 font-bold duration-500 ease-in-out text-white" aria-current="page">Subscribe</li>
            </ul>
        </div>
    </section><!--end section-->
    <div class="relative">
        <div class="shape absolute sm:-bottom-px -bottom-0.5 start-0 end-0 overflow-hidden z-1 text-gray-50 dark:text-slate-800">
            <svg class="w-full h-auto scale-[2.0] origin-top" viewBox="0 0 2880 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0 48H1437.5H2880V0H2160C1442.5 52 720 0 720 0H0V48Z" fill="currentColor"></path>
            </svg>
        </div>
    </div>
    <!-- End Hero -->

    <!-- Start Section-->
    <section id="subscribe-form-section" class="relative md:py-24 py-16 bg-gray-50 dark:bg-slate-800">
        <div class="container relative">
            <div class="grid md:grid-cols-12 grid-cols-1 gap-7.5 mx-auto text-center">
                <div class="lg:col-start-3 lg:col-span-8 md:col-start-2 md:col-span-10">
                    <div class="bg-white dark:bg-slate-900 rounded-md shadow-sm dark:shadow-gray-800 p-6">

                        <!-- Immediate benefit headline -->
                        <h3 class="mb-2 text-2xl leading-normal font-medium text-slate-900 dark:text-white">
                            Tell us what you’re looking for – we’ll send you the best matches every morning.
                        </h3>
                        <p class="text-slate-500 text-sm mb-4">
                            Fill in a few quick details and we’ll build a daily email with job leads that match your profile.
                        </p>

                        <!-- Value bullets -->
                        <ul class="text-sm text-slate-500 mb-6 text-left list-none space-y-2">
                            <li class="flex items-start">
                                <i class="ri-check-line text-primary mt-0.5 me-2"></i>
                                <span>Daily email with <span class="font-semibold">5–10 curated job leads</span> that match your profile.</span>
                            </li>
                            <li class="flex items-start">
                                <i class="ri-check-line text-primary mt-0.5 me-2"></i>
                                <span><span class="font-semibold">U.S. opportunities only</span> (remote and on-site, depending on your preferences).</span>
                            </li>
                            <li class="flex items-start">
                                <i class="ri-check-line text-primary mt-0.5 me-2"></i>
                                <span><span class="font-semibold">No login required.</span> Unsubscribe with one click anytime.</span>
                            </li>
                        </ul>

                        <form method="post" action="functions/subscribe-submit.php" name="leadForm" id="leadForm">
                            <!-- messages -->
                            <div id="error-msg" class="relative px-4 py-2 rounded-md font-medium bg-red-600 border border-red-600 text-white hidden mb-4"></div>
                            <div id="simple-msg" class="bg-emerald-600 border border-emerald-600 font-medium px-4 py-2 relative rounded-md text-white hidden mb-4"></div>

                            <div class="grid lg:grid-cols-12 lg:gap-6">
                                <div class="lg:col-span-6">
                                    <div class="text-start">
                                        <label for="first_name" class="font-semibold">First name <span class="text-red-500">*</span></label>
                                        <div class="form-icon relative mt-2">
                                            <i class="ri-user-line absolute top-2 start-3"></i>
                                            <input name="first_name" id="first_name" type="text"
                                                class="form-input ps-10 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                                placeholder="First name">
                                        </div>
                                    </div>
                                </div>

                                <div class="lg:col-span-6">
                                    <div class="text-start">
                                        <label for="last_name" class="font-semibold">Last name <span class="text-red-500">*</span></label>
                                        <div class="form-icon relative mt-2">
                                            <i class="ri-user-line absolute top-2 start-3"></i>
                                            <input name="last_name" id="last_name" type="text"
                                                class="form-input ps-10 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                                placeholder="Last name (optional)">
                                        </div>
                                    </div>
                                </div>

                                <div class="lg:col-span-12">
                                    <div class="text-start">
                                        <label for="email" class="font-semibold">Email address <span class="text-red-500">*</span></label>
                                        <div class="form-icon relative mt-2">
                                            <i class="ri-mail-line absolute top-2 start-3"></i>
                                            <input name="email" id="email" type="email"
                                                class="form-input ps-10 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                                placeholder="you@email.com">
                                        </div>
                                    </div>
                                </div>

                                <div class="lg:col-span-12">
                                    <div class="text-start">
                                        <label for="job_keyword" class="font-semibold">Job keyword <span class="text-red-500">*</span></label>
                                        <div class="form-icon relative mt-2">
                                            <i class="ri-briefcase-line absolute top-2 start-3"></i>
                                            <input name="job_keyword" id="job_keyword" type="text"
                                                class="form-input ps-10 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                                placeholder="warehouse, remote, driver">
                                        </div>
                                    </div>
                                </div>

                                <div class="lg:col-span-4">
                                    <div class="text-start">
                                        <label for="city" class="font-semibold">City <span class="text-red-500">*</span></label>
                                        <div class="form-icon relative mt-2">
                                            <i class="ri-map-pin-line absolute top-2 start-3"></i>
                                            <input name="city" id="city" type="text"
                                                class="form-input ps-10 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                                placeholder="Charlotte">
                                        </div>
                                    </div>
                                </div>

                                <div class="lg:col-span-4">
                                    <div class="text-start">
                                        <label for="state" class="font-semibold">State <span class="text-red-500">*</span></label>
                                        <div class="form-icon relative mt-2">
                                            <i class="ri-map-2-line absolute top-2 start-3"></i>
                                            <input name="state" id="state" type="text"
                                                class="form-input ps-10 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                                placeholder="NC">
                                        </div>
                                    </div>
                                </div>

                                <div class="lg:col-span-4">
                                    <div class="text-start">
                                        <label for="zip" class="font-semibold">ZIP <span class="text-red-500">*</span></label>
                                        <div class="form-icon relative mt-2">
                                            <i class="ri-mail-open-line absolute top-2 start-3"></i>
                                            <input name="zip" id="zip" type="text"
                                                class="form-input ps-10 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                                placeholder="28202">
                                        </div>
                                    </div>
                                </div>

                                <div class="lg:col-span-12">
                                    <div class="text-start">
                                        <label class="inline-flex items-start gap-2 cursor-pointer">
                                            <input type="checkbox" name="consent" id="consent" value="1" class="mt-1">
                                            <span class="text-slate-600 dark:text-slate-300 text-sm">
                                                I agree to receive job-related emails from <?= $siteTitle ?> and understand I can unsubscribe at any time.
                                                I have read the
                                                <a href="privacy.php<?= $linkCompleteHref ?>" class="text-primary underline" target="_blank" rel="noopener">
                                                    Privacy Policy
                                                </a>.
                                                <span class="text-red-500">*</span>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="cf-turnstile mb-4"
                                data-sitekey="<?= htmlspecialchars(getenv('TURNSTILE_SITEKEY') ?: '') ?>"
                                data-theme="auto">
                            </div>

                            <button type="submit" id="leadSubmitBtn"
                                class="py-2 px-5 font-semibold tracking-wide border align-middle duration-500 text-base text-center bg-primary hover:bg-primary-700 border-primary hover:border-primary-700 text-white rounded-md justify-center flex items-center w-full">
                                Get job leads
                            </button>

                            <!-- Button helper text -->
                            <p class="text-slate-400 text-sm mt-3 text-center">
                                No spam. Just curated job leads based on your profile.
                            </p>

                            <!-- Neutral statement (no fake social proof) -->
                            <p class="text-slate-400 text-xs mt-1 text-center italic">
                                We’re testing and improving based on real user feedback every week.
                            </p>
                        </form>

                    </div>
                </div>
            </div><!--end grid-->
        </div><!--end container-->
    </section><!--end section-->
    <!-- End Section-->

    <?php include __DIR__ . '/partials/footer.php'; ?>

</body>

</html>