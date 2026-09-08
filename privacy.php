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
                <h3 class="text-3xl leading-normal font-semibold">Privacy Policy</h3>
                <p class="text-slate-400 mt-2 text-sm">
                    Last updated: January 2026
                </p>
            </div><!--end grid-->
        </div><!--end container-->

        <div class="absolute text-center z-10 bottom-5 start-0 end-0 mx-3">
            <ul class="tracking-[0.5px] mb-0 inline-block">
                <li class="inline-block uppercase text-13 font-bold duration-500 ease-in-out hover:text-primary">
                    <a href="index.php<?= $linkCompleteHref ?>">Home</a>
                </li>
                <li class="inline-block text-base text-slate-950 dark:text-white mx-0.5 ltr:rotate-0 rtl:rotate-180">
                    <i class="ri-arrow-right-s-line"></i>
                </li>
                <li class="inline-block uppercase text-13 font-bold text-primary" aria-current="page">Privacy</li>
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

    <!-- Start Privacy -->
    <section class="relative md:py-24 py-16">
        <div class="container relative">
            <div class="md:flex justify-center">
                <div class="md:w-3/4">
                    <div class="p-6 bg-white dark:bg-slate-900 shadow-sm dark:shadow-gray-800 rounded-md">

                        <!-- 1. Overview -->
                        <h5 class="text-xl font-semibold mb-4">1. Overview</h5>
                        <p class="text-slate-400 mb-4">
                            <?= $siteTitle ?> (“we”, “us”, or “our”) helps job seekers in the U.S. receive curated job leads
                            by email. This Privacy Policy explains what information we collect, how we use it, with whom we
                            share it, and what choices you have.
                        </p>
                        <p class="text-slate-400">
                            By using our website, subscribing to our emails, or interacting with our job alerts, you agree
                            to this Privacy Policy. If you do not agree, please do not use our services.
                        </p>

                        <!-- 2. Information we collect -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">2. Information we collect</h5>

                        <h6 class="font-semibold mb-2">2.1 Information you provide</h6>
                        <p class="text-slate-400 mb-2">
                            When you subscribe or submit a form on our website, we collect information such as:
                        </p>
                        <ul class="list-none text-slate-400 mt-2 mb-4">
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Name
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Email address
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Job preferences (for example: job keyword or role of interest)
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Location details (for example: city, state, ZIP code)
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Any other information you choose to share in our forms or messages
                            </li>
                        </ul>

                        <h6 class="font-semibold mb-2">2.2 Information collected automatically</h6>
                        <p class="text-slate-400 mb-2">
                            When you visit our website or open our emails, we automatically collect certain information,
                            including:
                        </p>
                        <ul class="list-none text-slate-400 mt-2 mb-4">
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                IP address, browser type, device type, and operating system
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Pages visited, time spent, and basic usage information on our website
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Email engagement data (for example: if an email was delivered, opened, or a link was clicked)
                            </li>
                        </ul>

                        <h6 class="font-semibold mb-2">2.3 Information related to job links</h6>
                        <p class="text-slate-400 mb-2">
                            All job details and applications are hosted by our partners. We do not control hiring decisions
                            or job availability.
                        </p>
                        <p class="text-slate-400">
                            When you click on a job link in our emails or on our website, that click may be tracked so we can
                            understand which jobs generated interest and improve future recommendations. The job site you land on
                            is operated by a third party and will process your information according to its own privacy policy.
                        </p>

                        <!-- 3. How we use your information -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">3. How we use your information</h5>
                        <p class="text-slate-400 mb-2">
                            We use the information we collect for the following purposes:
                        </p>
                        <ul class="list-none text-slate-400 mt-2">
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                To send you job lead emails and service communications related to your subscription.
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                To match you with relevant job opportunities based on your job keyword and location.
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                To analyze email performance (opens, clicks, deliverability) and improve our campaigns.
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                To operate, maintain, and improve our website and email infrastructure.
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                To detect, prevent, and respond to spam, abuse, or other harmful activity.
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                To comply with legal obligations or enforce our terms when necessary.
                            </li>
                        </ul>

                        <!-- 4. When and how we share information -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">4. When and how we share information</h5>

                        <h6 class="font-semibold mb-2">4.1 Email service provider</h6>
                        <p class="text-slate-400 mb-4">
                            We use a third-party email service provider (currently Brevo / Sendinblue) to send our emails,
                            manage our contact lists, and track email performance. Your contact information (such as name
                            and email address) is stored and processed by this provider solely to deliver and analyze our
                            email campaigns on our behalf.
                        </p>

                        <h6 class="font-semibold mb-2">4.2 Trusted third-party job networks</h6>
                        <p class="text-slate-400 mb-2">
                            We work with trusted third-party job networks and partners to source job postings. In general:
                        </p>
                        <ul class="list-none text-slate-400 mt-2 mb-4">
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                When you click a job link in our emails or on our website, you are redirected to a third-party job site
                                or employer site.
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                We may include tracking parameters in the link (for example, a campaign or referral ID) so
                                the job network can measure traffic and we can understand which campaigns generated visits.
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                We do not provide your email address directly to job networks just because you receive our
                                emails. If you apply for a job or create an account on a partner's site, you share your data
                                with them directly under their own terms and privacy policy.
                            </li>
                        </ul>

                        <h6 class="font-semibold mb-2">4.3 Legal and business transfers</h6>
                        <p class="text-slate-400 mb-4">
                            We may disclose information if required by law, regulation, legal process, or governmental
                            request, or when we believe it is necessary to protect our rights, users, or the public. In the
                            event of a business transaction (such as a merger, acquisition, or asset sale), your information
                            may be transferred as part of that transaction, subject to continued protection consistent with
                            this Privacy Policy.
                        </p>

                        <!-- 5. Cookies and tracking technologies -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">5. Cookies and tracking technologies</h5>

                        <h6 class="font-semibold mb-2">5.1 On our website</h6>
                        <p class="text-slate-400 mb-4">
                            Our website may use cookies and similar technologies to remember your preferences, improve site
                            performance, and understand how visitors use our pages. Cookies are small text files stored on
                            your device by your browser.
                        </p>

                        <h6 class="font-semibold mb-2">5.2 In our emails</h6>
                        <p class="text-slate-400 mb-2">
                            Our email provider uses tracking technologies such as:
                        </p>
                        <ul class="list-none text-slate-400 mt-2 mb-4">
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Tracking pixels to know if an email was opened (when your email client loads images).
                            </li>
                            <li class="flex mt-1">
                                <i class="ri-arrow-right-line text-primary text-lg align-middle me-2"></i>
                                Tracked links to understand which job links or buttons were clicked.
                            </li>
                        </ul>
                        <p class="text-slate-400">
                            You can limit some of this tracking by disabling images in your email client or by not clicking
                            certain links, but this may reduce the usefulness of our emails.
                        </p>

                        <!-- 6. Your choices and rights -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">6. Your choices and rights</h5>

                        <h6 class="font-semibold mb-2">6.1 Unsubscribe from emails</h6>
                        <p class="text-slate-400 mb-4">
                            Every job lead email we send includes an unsubscribe link at the bottom. You can click this link
                            at any time to stop receiving our marketing and job lead emails. We may still send essential
                            service messages if strictly necessary (for example, to confirm a change in your subscription).
                        </p>

                        <h6 class="font-semibold mb-2">6.2 Access, update, or delete your information</h6>
                        <p class="text-slate-400 mb-4">
                            You can request to access, update, or delete the personal information we hold about you by
                            contacting us using the contact details provided on our website (for example, the email address
                            in the site footer). We will handle your request within a reasonable time, subject to any legal
                            obligations to retain certain data.
                        </p>

                        <h6 class="font-semibold mb-2">6.3 Opt-out of certain tracking</h6>
                        <p class="text-slate-400">
                            You can adjust your browser settings to block cookies and configure your email client to block
                            remote images. Note that some features of our website or emails may not function properly if you
                            disable these technologies.
                        </p>

                        <!-- 7. Data retention and security -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">7. Data retention and security</h5>
                        <p class="text-slate-400 mb-4">
                            We retain your information for as long as it is reasonably necessary to provide our services,
                            operate our email list, analyze performance, and meet legal or accounting requirements. If you
                            unsubscribe or request deletion, we will remove you from our active mailing lists and, where
                            feasible, from our operational systems, while keeping only the minimum data required for legal,
                            security, or anti-abuse purposes.
                        </p>
                        <p class="text-slate-400">
                            We use technical and organizational measures to protect your information, including access
                            controls and secure hosting. However, no system can be guaranteed to be 100% secure.
                        </p>

                        <!-- 8. Children’s privacy -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">8. Children’s privacy</h5>
                        <p class="text-slate-400">
                            Our services are not directed to children, and we do not knowingly collect personal information
                            from individuals under 16. If you believe that a child has provided us with personal information,
                            please contact us so we can take appropriate action.
                        </p>

                        <!-- 9. Changes to this Privacy Policy -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">9. Changes to this Privacy Policy</h5>
                        <p class="text-slate-400 mb-4">
                            We may update this Privacy Policy from time to time to reflect changes in our services or
                            applicable laws. When we do, we will revise the “Last updated” date at the top of this page. We
                            encourage you to review this page periodically.
                        </p>

                        <!-- 10. Contact -->
                        <h5 class="text-xl font-semibold mb-4 mt-8">10. Contact</h5>
                        <p class="text-slate-400">
                            If you have any questions about this Privacy Policy or how we handle your information, please
                            reach out using the contact details available on our website.
                        </p>

                    </div>
                </div><!--end -->
            </div><!--end grid-->
        </div><!--end container-->
    </section><!--end section-->
    <!-- End Privacy -->

    <?php include __DIR__ . '/partials/footer.php'; ?>
</body>

</html>