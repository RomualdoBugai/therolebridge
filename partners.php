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
    <section class="relative table w-full py-32 lg:py-40 bg-[url('../../assets/images/company/aboutus.jpg')] bg-center bg-no-repeat bg-cover">
        <div class="absolute inset-0 bg-slate-900 opacity-80"></div>

        <div class="container relative">
            <div class="grid grid-cols-1 text-center mt-10">
                <h3 class="md:text-4xl text-3xl md:leading-normal leading-normal font-semibold text-white">
                    For Partners & Job Networks
                </h3>
                <p class="text-slate-200 mt-3 max-w-2xl mx-auto">
                    <?= $siteTitle ?> connects job seekers in the U.S. with curated opportunities via high-intent,
                    email-first distribution. We work with job boards, aggregators, and staffing firms to drive
                    incremental, performance-based traffic.
                </p>
            </div><!--end grid-->
        </div><!--end container-->

        <div class="absolute text-center z-10 bottom-5 start-0 end-0 mx-3">
            <ul class="tracking-[0.5px] mb-0 inline-block">
                <li class="inline-block uppercase text-13 font-bold duration-500 ease-in-out text-white/50 hover:text-white">
                    <a href="index.php<?= $linkCompleteHref ?>">Home</a>
                </li>
                <li class="inline-block text-base text-white/50 mx-0.5 ltr:rotate-0 rtl:rotate-180">
                    <i class="ri-arrow-right-s-line"></i>
                </li>
                <li class="inline-block uppercase text-13 font-bold text-white" aria-current="page">
                    For Partners
                </li>
            </ul>
        </div>
    </section><!--end section-->

    <div class="relative">
        <div class="shape absolute sm:-bottom-px -bottom-0.5 start-0 end-0 overflow-hidden z-1 text-white dark:text-slate-900">
            <svg class="w-full h-auto scale-[2.0] origin-top" viewBox="0 0 2880 48" fill="none"
                xmlns="http://www.w3.org/2000/svg">
                <path d="M0 48H1437.5H2880V0H2160C1442.5 52 720 0 720 0H0V48Z" fill="currentColor"></path>
            </svg>
        </div>
    </div>
    <!-- End Hero -->

    <!-- Start Partners Section -->
    <section class="relative md:py-24 py-16 bg-gray-50 dark:bg-slate-800">
        <div class="container relative">
            <div class="grid md:grid-cols-12 grid-cols-1 gap-10 items-start">
                <!-- Left: copy de posicionamento -->
                <div class="md:col-span-7 mr-2">
                    <h4 class="text-2xl md:text-3xl font-semibold mb-4">
                        Who we work with
                    </h4>
                    <p class="text-slate-500 mb-4">
                        <?= $siteTitle ?> is an email-first job discovery product focused on U.S. job seekers. We partner with:
                    </p>
                    <ul class="list-none text-slate-500 space-y-2 mb-6">
                        <li class="flex">
                            <i class="ri-check-line text-primary text-lg align-middle me-2"></i>
                            <span>Job boards and aggregators looking to increase high-intent clicks.</span>
                        </li>
                        <li class="flex">
                            <i class="ri-check-line text-primary text-lg align-middle me-2"></i>
                            <span>Staffing agencies and RPOs promoting specific roles or campaigns.</span>
                        </li>
                        <li class="flex">
                            <i class="ri-check-line text-primary text-lg align-middle me-2"></i>
                            <span>Job networks and performance partners working on CPC-based models.</span>
                        </li>
                    </ul>

                    <h4 class="text-2xl font-semibold mb-4">
                        How we monetize and distribute
                    </h4>
                    <p class="text-slate-500 mb-4">
                        Our core model is simple: we send daily, curated job emails to opted-in U.S. job seekers, and
                        route traffic to partner destinations on a performance basis (CPC where applicable).
                    </p>
                    <ul class="list-none text-slate-500 space-y-2 mb-6">
                        <li class="flex">
                            <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                            <span>
                                <strong>CPC-based traffic:</strong> we drive qualified, trackable clicks to your jobs or
                                search results pages.
                            </span>
                        </li>
                        <li class="flex">
                            <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                            <span>
                                <strong>Email distribution:</strong> daily placements in our job alert emails, with links
                                pointing directly to your site.
                            </span>
                        </li>
                        <li class="flex">
                            <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                            <span>
                                <strong>Segmentation & filtering:</strong> targeting by location (city/state/ZIP),
                                job keyword, and engagement history.
                            </span>
                        </li>
                    </ul>

                    <h4 class="text-2xl font-semibold mb-4">
                        Benefits for partners
                    </h4>
                    <div class="grid md:grid-cols-2 grid-cols-1 gap-4">
                        <div class="p-4 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800">
                            <h6 class="font-semibold mb-1">Incremental, high-intent traffic</h6>
                            <p class="text-slate-500 text-sm">
                                Our audience subscribes specifically for job leads, which tends to convert better than broad,
                                untargeted traffic.
                            </p>
                        </div>
                        <div class="p-4 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800">
                            <h6 class="font-semibold mb-1">Daily email placements</h6>
                            <p class="text-slate-500 text-sm">
                                Consistent presence in job alert emails, keeping your jobs visible to active seekers.
                            </p>
                        </div>
                        <div class="p-4 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800">
                            <h6 class="font-semibold mb-1">Controlled testing</h6>
                            <p class="text-slate-500 text-sm">
                                Start small, test performance, and scale bids / volume based on results and quality.
                            </p>
                        </div>
                        <div class="p-4 rounded-md bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800">
                            <h6 class="font-semibold mb-1">Clean compliance</h6>
                            <p class="text-slate-500 text-sm">
                                Fully opted-in list, clear unsubscribe, and transparent data practices for long-term
                                deliverability.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Right: form de lead parceiro -->
                <div class="md:col-span-5">
                    <div class="bg-white dark:bg-slate-900 rounded-md shadow-sm dark:shadow-gray-800 p-6">
                        <h5 class="text-xl font-semibold mb-2">Partner with <?= $siteTitle ?></h5>
                        <p class="text-slate-500 text-sm mb-4">
                            Tell us a bit about your company and goals. We’ll get back to you to discuss fit, traffic
                            estimates, and CPC options.
                        </p>

                        <form action="functions/partner-submit.php" method="post" id="partnerForm" name="partnerForm">
                            <!-- mensagens -->
                            <div id="partner-error-msg"
                                class="hidden bottom-3 relative px-4 py-2 rounded-md font-medium bg-red-600 border border-red-600 text-white block"></div>
                            <div id="partner-simple-msg"
                                class="hidden bottom-3 bg-emerald-600 block border border-emerald-600 font-medium px-4 py-2 relative rounded-md text-white"></div>

                            <div class="mb-4 text-start">
                                <label for="company_name" class="font-semibold text-sm">
                                    Company name <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    name="company_name"
                                    id="company_name"
                                    class="form-input mt-2 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                    placeholder="Your company or brand">
                            </div>

                            <div class="mb-4 text-start">
                                <label for="website" class="font-semibold text-sm">
                                    Website <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="url"
                                    name="website"
                                    id="website"
                                    class="form-input mt-2 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                    placeholder="https://example.com">
                            </div>

                            <div class="mb-4 text-start">
                                <label for="contact_email" class="font-semibold text-sm">
                                    Contact email <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="email"
                                    name="contact_email"
                                    id="contact_email"
                                    class="form-input mt-2 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                    placeholder="you@company.com">
                            </div>

                            <div class="mb-4 text-start">
                                <label for="monthly_budget" class="font-semibold text-sm">
                                    Approx. monthly budget range
                                </label>
                                <select
                                    name="monthly_budget"
                                    id="monthly_budget"
                                    class="form-input mt-2 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0">
                                    <option value="">Select a range (optional)</option>
                                    <option value="under_1k">Under $1,000 / month</option>
                                    <option value="1k_5k">$1,000 – $5,000 / month</option>
                                    <option value="5k_15k">$5,000 – $15,000 / month</option>
                                    <option value="15k_plus">$15,000+ / month</option>
                                </select>
                            </div>

                            <div class="mb-5 text-start">
                                <label for="message" class="font-semibold text-sm">
                                    What are you looking for?
                                </label>
                                <textarea
                                    name="message"
                                    id="message"
                                    rows="4"
                                    class="form-input mt-2 w-full py-2 px-3 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                    placeholder="Short description of your jobs, markets, and performance goals."></textarea>
                            </div>

                            <div class="cf-turnstile mb-4"
                                data-sitekey="<?= htmlspecialchars(getenv('TURNSTILE_SITEKEY') ?: '') ?>"
                                data-theme="auto">
                            </div>

                            <button
                                type="submit"
                                id="partnerSubmitBtn"
                                class="py-2 px-5 font-semibold tracking-wide border align-middle duration-500 text-base text-center bg-primary hover:bg-primary-700 border-primary hover:border-primary-700 text-white rounded-md justify-center flex items-center w-full">
                                Submit partner request
                            </button>

                            <p class="text-slate-400 text-xs mt-3">
                                We’ll review your information and reply by email. No commitments until both sides agree on terms and setup.
                            </p>
                        </form>
                    </div>
                </div>

            </div><!--end grid-->
        </div><!--end container-->
    </section>
    <!-- End Partners Section -->

    <?php include __DIR__ . '/partials/footer.php'; ?>

</body>

</html>