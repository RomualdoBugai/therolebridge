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
    <section class="relative table w-full py-36 bg-[url('../../assets/images/company/aboutus.jpg')] bg-center bg-no-repeat bg-cover">
        <div class="absolute inset-0 bg-slate-900 opacity-75"></div>
        <div class="container relative">
            <div class="grid grid-cols-1 pb-8 text-center mt-10">
                <h3 class="md:text-4xl text-3xl md:leading-normal tracking-wide leading-normal font-medium text-white">
                    Contact <?= $siteTitle ?>
                </h3>
                <p class="mt-4 max-w-2xl mx-auto text-slate-200">
                    Questions about your email campaigns, partnerships or job leads? Talk to our team.
                </p>
                <!-- NOVO: prova de que alguém responde + instrução de urgência -->
                <p class="mt-2 max-w-2xl mx-auto text-slate-200 text-sm">
                    We typically reply within 1–2 business days. For urgent questions about your data or subscription,
                    mention “Privacy” or “Unsubscribe” in the subject.
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
                <li class="inline-block uppercase text-13 font-bold duration-500 ease-in-out text-white" aria-current="page">
                    Contact
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

    <!-- Start Section-->
    <section class="relative">

        <div class="container relative md:mt-24 mt-16">
            <div class="grid md:grid-cols-12 grid-cols-1 items-center gap-7.5">
                <div class="lg:col-span-7 md:col-span-6">
                    <img src="assets/images/contact.svg" alt="Contact <?= htmlspecialchars($siteTitle) ?>">
                </div>

                <div class="lg:col-span-5 md:col-span-6">
                    <div class="lg:ms-5">
                        <div class="bg-white dark:bg-slate-900 rounded-md shadow-sm dark:shadow-gray-800 p-6">
                            <h3 class="mb-6 text-2xl leading-normal font-medium">Get in touch</h3>
                            <p class="text-slate-400 mb-6">
                                Send us a message about your question, integration or campaign idea. We read every message and route it to the right person.
                            </p>

                            <form method="post" action="functions/contact-submit.php" name="contactForm" id="contactForm">
                                <div id="error-msg" class="hidden bottom-3 relative px-4 py-2 rounded-md font-medium bg-red-600 border border-red-600 text-white block"></div>
                                <div id="simple-msg" class="hidden bottom-3 bg-emerald-600 block border border-emerald-600 font-medium px-4 py-2 relative rounded-md text-white"></div>

                                <div class="grid lg:grid-cols-12 lg:gap-6">
                                    <div class="lg:col-span-6 mb-5">
                                        <div class="text-start">
                                            <label for="name" class="font-semibold">Your Name</label>
                                            <div class="form-icon relative mt-2">
                                                <i class="ri-user-line absolute top-2 start-3"></i>
                                                <input name="name" id="name" type="text"
                                                    class="form-input ps-10 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                                    placeholder="Full name">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="lg:col-span-6 mb-5">
                                        <div class="text-start">
                                            <label for="email" class="font-semibold">Your Email</label>
                                            <div class="form-icon relative mt-2">
                                                <i class="ri-mail-line absolute top-2 start-3"></i>
                                                <input name="email" id="email" type="email"
                                                    class="form-input ps-10 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                                    placeholder="you@example.com">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1">
                                    <!-- NOVO: dropdown de tipo de assunto -->
                                    <div class="mb-5">
                                        <div class="text-start">
                                            <label for="topic_type" class="font-semibold">Topic type</label>
                                            <div class="form-icon relative mt-2">
                                                <i class="ri-list-check-2 absolute top-2 start-3"></i>
                                                <select name="topic_type" id="topic_type"
                                                    class="form-input ps-10 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0">
                                                    <option value="">Select a topic</option>
                                                    <option value="job_seeker">Job seeker question</option>
                                                    <option value="partnership">Partnership / Job network</option>
                                                    <option value="technical">Technical issue</option>
                                                    <option value="privacy">Privacy / Data / Unsubscribe</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-5">
                                        <div class="text-start">
                                            <label for="subject" class="font-semibold">Subject</label>
                                            <div class="form-icon relative mt-2">
                                                <i class="ri-question-line absolute top-2 start-3"></i>
                                                <input name="subject" id="subject"
                                                    class="form-input ps-10 w-full py-2 px-3 h-10 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                                    placeholder="e.g. Partnership, Support, Privacy, Campaigns">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-5">
                                        <div class="text-start">
                                            <label for="comments" class="font-semibold">Your Message</label>
                                            <div class="form-icon relative mt-2">
                                                <i class="ri-chat-1-line absolute top-2 start-3"></i>
                                                <textarea name="comments" id="comments"
                                                    class="form-input ps-10 w-full py-2 px-3 h-28 bg-transparent dark:bg-slate-900 dark:text-slate-200 rounded outline-none border border-gray-200 focus:border-primary dark:border-gray-800 dark:focus:border-primary focus:ring-0"
                                                    placeholder="Tell us how we can help"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="cf-turnstile mb-4"
                                    data-sitekey="<?= htmlspecialchars(getenv('TURNSTILE_SITEKEY') ?: '') ?>"
                                    data-theme="auto">
                                </div>

                                <button type="submit" id="submit" name="send"
                                    class="py-2 px-5 font-semibold tracking-wide border align-middle duration-500 text-base text-center bg-primary hover:bg-primary-700 border-primary hover:border-primary-700 text-white rounded-md justify-center flex items-center">
                                    Send Message
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div><!--end container-->
    </section>
    <!-- End Section-->

    <div class="container-fluid relative">
        <div class="grid grid-cols-1">
            <div class="w-full leading-0 border-0">
                <!-- Mapa focado em Charlotte, NC (pode trocar depois se quiser um endereço específico) -->
                <iframe
                    src="https://www.google.com/maps?q=Charlotte,+North+Carolina&output=embed"
                    style="border:0"
                    class="w-full h-125"
                    allowfullscreen>
                </iframe>
            </div>
        </div><!--end grid-->
    </div><!--end container-->

    <?php include __DIR__ . '/partials/footer.php'; ?>

</body>

</html>