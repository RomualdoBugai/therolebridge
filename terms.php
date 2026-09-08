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
                <h3 class="text-3xl leading-normal font-semibold">Terms of Service</h3>
                <p class="mt-4 text-slate-500 dark:text-slate-300">
                    Please read these Terms of Service carefully before using <?= $siteTitle ?>.
                </p>
            </div><!--end grid-->
        </div><!--end container-->

        <div class="absolute text-center z-10 bottom-5 start-0 end-0 mx-3">
            <ul class="tracking-[0.5px] mb-0 inline-block">
                <li class="inline-block uppercase text-13 font-bold duration-500 ease-in-out hover:text-primary">
                    <a href="index.php<?= $linkCompleteHref ?>">
                        Home
                    </a>
                </li>
                <li class="inline-block text-base text-slate-950 dark:text-white mx-0.5 ltr:rotate-0 rtl:rotate-180">
                    <i class="ri-arrow-right-s-line"></i>
                </li>
                <li class="inline-block uppercase text-13 font-bold text-primary" aria-current="page">
                    Terms of Use
                </li>
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

    <!-- Start Terms & Conditions -->
    <section class="relative md:py-24 py-16">
        <div class="container relative">
            <div class="md:flex justify-center">
                <div class="md:w-3/4">
                    <div class="p-6 bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800 rounded-md">
                        <!-- INTRODUCTION -->
                        <h5 class="text-xl font-semibold mb-4">1. Introduction</h5>
                        <p class="text-slate-400">
                            <?= $siteTitle ?> (“we”, “us”, “our”) is a service that curates and delivers job leads to your
                            inbox, based on the information you provide. By accessing our website, subscribing to our
                            emails, or using any of our services (collectively, the “Service”), you agree to these
                            Terms of Service (“Terms”).
                        </p>
                        <p class="text-slate-400 mt-3">
                            If you do not agree with these Terms, please do not use <?= $siteTitle ?>. We may update these
                            Terms from time to time. When we do, we will update the “Last updated” date on this page, and
                            your continued use of the Service means you accept the updated Terms.
                        </p>

                        <!-- USER AGREEMENTS -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">2. User Agreement & Use of the Service</h5>
                        <p class="text-slate-400">
                            When you use <?= $siteTitle ?>, you agree that:
                        </p>
                        <ul class="list-disc ms-5 text-slate-400 mt-3 space-y-1">
                            <li>You are at least 18 years old and legally able to enter into these Terms.</li>
                            <li>You will provide accurate and up-to-date information (such as your email address and job preferences).</li>
                            <li>You understand that we <span class="font-semibold">do not guarantee employment, interviews, or job offers</span>.</li>
                            <li>You apply directly with employers or third-party job sites, and they are solely responsible for their job postings, hiring decisions, and communication.</li>
                            <li>We may use third-party partners, APIs, and affiliate networks to source job leads and may earn fees or commissions when you click or apply through certain links.</li>
                            <li>We may send you marketing and job-related emails in line with your subscription preferences, and every email contains an option to unsubscribe.</li>
                        </ul>

                        <p class="text-slate-400 mt-3">
                            We reserve the right to modify, suspend, or discontinue the Service (or any part of it) at any
                            time, with or without notice, and without liability to you.
                        </p>

                        <!-- RESTRICTIONS -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">3. Restrictions</h5>
                        <p class="text-slate-400">
                            You are specifically restricted from all of the following while using <?= $siteTitle ?>:
                        </p>
                        <ul class="list-none text-slate-400 mt-3">
                            <li class="flex mt-2">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Using the Service to send spam, unsolicited bulk messages, or any other abusive activity.
                            </li>
                            <li class="flex mt-2">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Scraping, copying, reselling, or redistributing job leads or email content without our prior written consent.
                            </li>
                            <li class="flex mt-2">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Misrepresenting your identity, impersonating another person, or providing false information.
                            </li>
                            <li class="flex mt-2">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Attempting to gain unauthorized access to our systems, interfering with the security or availability of the Service, or introducing malware.
                            </li>
                            <li class="flex mt-2">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Using the Service for any illegal, fraudulent, or harmful purpose, including discrimination or harassment.
                            </li>
                            <li class="flex mt-2">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Bypassing or attempting to bypass any limits on email volume, frequency, or use that we define.
                            </li>
                        </ul>

                        <!-- LIABILITY / DISCLAIMER (SHORT) -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">4. No Guarantee of Employment & Third-Party Content</h5>
                        <p class="text-slate-400">
                            <?= $siteTitle ?> is <span class="font-semibold">not</span> a recruiter, employer, or employment
                            agency. We do not participate in the hiring process and cannot control:
                        </p>
                        <ul class="list-disc ms-5 text-slate-400 mt-3 space-y-1">
                            <li>Whether a job is still available or accurate at the time you view it.</li>
                            <li>How employers or third-party sites handle your application or personal data.</li>
                            <li>Any hiring, interviewing, or compensation decisions made by third parties.</li>
                        </ul>
                        <p class="text-slate-400 mt-3">
                            All job listings and external links are provided “as is” by third-party sources. We are not
                            responsible for the content, accuracy, policies, or practices of third-party websites, apps, or
                            employers. You should review the terms and privacy policies of any external sites you use.
                        </p>

                        <!-- PRIVACY / EMAILS -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">5. Email Communication, Privacy & Opt-Out</h5>
                        <p class="text-slate-400">
                            By subscribing, you authorize us to send you job leads and related content by email to the address
                            you provided. We aim to comply with applicable email and anti-spam laws. You may unsubscribe at
                            any time by clicking the unsubscribe link in the footer of any email or by contacting us directly.
                        </p>
                        <p class="text-slate-400 mt-3">
                            For more details on how we collect, use, and protect your personal information, please refer to
                            our <a href="privacy.php<?= $linkCompleteHref ?>" class="text-primary underline hover:text-primary-700">Privacy Policy</a>.
                        </p>

                        <!-- LIMITATION OF LIABILITY -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">6. Limitation of Liability</h5>
                        <p class="text-slate-400">
                            To the maximum extent permitted by law, <?= $siteTitle ?> and its owners, partners, and affiliates
                            are not liable for any indirect, incidental, special, or consequential damages, loss of
                            opportunities, or loss of income arising out of or related to your use of the Service, your
                            reliance on any job lead, or interactions with third-party websites or employers.
                        </p>

                        <!-- CHANGES / CONTACT -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">7. Changes & Contact</h5>
                        <p class="text-slate-400">
                            We may update these Terms periodically to reflect changes in our Service or applicable laws.
                            Your continued use of <?= $siteTitle ?> after updates are posted means you accept the new Terms.
                        </p>
                        <p class="text-slate-400 mt-3">
                            If you have questions about these Terms or our Service, you can contact us at
                            <a href="mailto:contact@therolebridge.com" class="text-primary underline hover:text-primary-700">
                                contact@therolebridge.com
                            </a>.
                        </p>

                        <!-- FAQ -->
                        <h5 class="text-xl font-semibold mt-8">Users Questions & Answers</h5>

                        <div id="accordion-collapse" data-accordion="collapse" class="mt-6">
                            <!-- Q1 -->
                            <div class="relative shadow-sm dark:shadow-gray-800 rounded-md overflow-hidden mt-4">
                                <h2 class="text-base font-semibold" id="accordion-collapse-heading-1">
                                    <button type="button"
                                        class="flex justify-between items-center p-5 w-full font-medium text-start"
                                        data-accordion-target="#accordion-collapse-body-1"
                                        aria-expanded="true"
                                        aria-controls="accordion-collapse-body-1">
                                        <span>How does <?= $siteTitle ?> work?</span>
                                        <svg data-accordion-icon class="size-4 rotate-180 shrink-0" fill="currentColor"
                                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                            <path fill-rule="evenodd"
                                                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                clip-rule="evenodd"></path>
                                        </svg>
                                    </button>
                                </h2>
                                <div id="accordion-collapse-body-1" class="hidden"
                                    aria-labelledby="accordion-collapse-heading-1">
                                    <div class="p-5">
                                        <p class="text-slate-400 dark:text-gray-400">
                                            We use your preferences (like location or job interests) to find relevant job
                                            leads from third-party partners and public job sources. We then send these leads
                                            to your inbox so you can review and apply directly with the employer or job
                                            site. The service is focused on helping you discover opportunities more easily,
                                            but we are not involved in the hiring process.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Q2 -->
                            <div class="relative shadow-sm dark:shadow-gray-800 rounded-md overflow-hidden mt-4">
                                <h2 class="text-base font-semibold" id="accordion-collapse-heading-2">
                                    <button type="button"
                                        class="flex justify-between items-center p-5 w-full font-medium text-start"
                                        data-accordion-target="#accordion-collapse-body-2"
                                        aria-expanded="false"
                                        aria-controls="accordion-collapse-body-2">
                                        <span>How often will you email me?</span>
                                        <svg data-accordion-icon class="size-4 shrink-0" fill="currentColor"
                                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                            <path fill-rule="evenodd"
                                                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                clip-rule="evenodd"></path>
                                        </svg>
                                    </button>
                                </h2>
                                <div id="accordion-collapse-body-2" class="hidden"
                                    aria-labelledby="accordion-collapse-heading-2">
                                    <div class="p-5">
                                        <p class="text-slate-400 dark:text-gray-400">
                                            Our goal is to send you helpful job leads without overwhelming your inbox. The
                                            exact frequency may vary, but typically you can expect up to a few emails per
                                            day when there are relevant opportunities. You can unsubscribe at any time using
                                            the link at the bottom of any email.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Q3 -->
                            <div class="relative shadow-sm dark:shadow-gray-800 rounded-md overflow-hidden mt-4">
                                <h2 class="text-base font-semibold" id="accordion-collapse-heading-3">
                                    <button type="button"
                                        class="flex justify-between items-center p-5 w-full font-medium text-start"
                                        data-accordion-target="#accordion-collapse-body-3"
                                        aria-expanded="false"
                                        aria-controls="accordion-collapse-body-3">
                                        <span>Does <?= $siteTitle ?> guarantee I will get a job?</span>
                                        <svg data-accordion-icon class="size-4 shrink-0" fill="currentColor"
                                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                            <path fill-rule="evenodd"
                                                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                clip-rule="evenodd"></path>
                                        </svg>
                                    </button>
                                </h2>
                                <div id="accordion-collapse-body-3" class="hidden"
                                    aria-labelledby="accordion-collapse-heading-3">
                                    <div class="p-5">
                                        <p class="text-slate-400 dark:text-gray-400">
                                            No. We do not guarantee any interviews, offers, or employment outcomes. Our role
                                            is to help you discover opportunities by sending you job leads. All hiring
                                            decisions are made by the employers or third-party platforms where you apply.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Q4 -->
                            <div class="relative shadow-sm dark:shadow-gray-800 rounded-md overflow-hidden mt-4">
                                <h2 class="text-base font-semibold" id="accordion-collapse-heading-4">
                                    <button type="button"
                                        class="flex justify-between items-center p-5 w-full font-medium text-start"
                                        data-accordion-target="#accordion-collapse-body-4"
                                        aria-expanded="false"
                                        aria-controls="accordion-collapse-body-4">
                                        <span>How can I unsubscribe or delete my data?</span>
                                        <svg data-accordion-icon class="size-4 shrink-0" fill="currentColor"
                                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                            <path fill-rule="evenodd"
                                                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                clip-rule="evenodd"></path>
                                        </svg>
                                    </button>
                                </h2>
                                <div id="accordion-collapse-body-4" class="hidden"
                                    aria-labelledby="accordion-collapse-heading-4">
                                    <div class="p-5">
                                        <p class="text-slate-400 dark:text-gray-400">
                                            You can unsubscribe instantly by clicking the unsubscribe link at the bottom of
                                            any <?= $siteTitle ?> email. If you want to request deletion of your data or have
                                            a privacy question, contact us at
                                            <a href="mailto:contact@therolebridge.com"
                                                class="text-primary underline hover:text-primary-700">
                                                contact@therolebridge.com
                                            </a>.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ACTION BUTTONS -->
                        <div class="mt-6">
                            <a href="index.php<?= $linkCompleteHref ?>"
                                class="py-2 px-5 inline-block font-semibold tracking-wide border align-middle duration-500 text-base text-center bg-primary hover:bg-primary-700 border-primary hover:border-primary-700 text-white rounded-md">
                                Back to Home
                            </a>
                            <a href="contact.php<?= $linkCompleteHref ?>"
                                class="py-2 px-5 inline-block font-semibold tracking-wide border align-middle duration-500 text-base text-center bg-transparent hover:bg-primary border-primary text-primary hover:text-white rounded-md ms-2">
                                Contact Support
                            </a>
                        </div>
                    </div>
                </div><!--end -->
            </div><!--end grid-->
        </div><!--end container-->
    </section>
    <!-- End Terms & Conditions -->


    <?php include __DIR__ . '/partials/footer.php'; ?>
</body>

</html>