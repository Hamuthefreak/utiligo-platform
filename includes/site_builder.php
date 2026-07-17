<?php
/**
 * includes/site_builder.php — Builds a complete multi-page static website
 * (Home, About, Services, Gallery, Contact) for a business, styled by the
 * chosen template. Pure HTML/CSS, no login/backend — designed to be handed
 * off to a client as-is or hosted anywhere.
 *
 * Every editable text block and image carries a `data-edit-id` attribute
 * (and text blocks also get `data-edit-type="text"`) so the interactive
 * site editor (portal/site_editor.php + assets/js/site_editor.js) can find,
 * highlight, and modify each piece independently, then persist changes via
 * api/save-site-edit.php without needing to regenerate the whole page.
 */
require_once __DIR__ . '/site_templates.php';

function build_site_pages(array $business, string $templateKey, array $customImages = []): array
{
    $t = get_site_template($templateKey);
    $name = $business['name'];
    $category = $business['category'];
    $city = $business['city'];
    $phone = $business['phone'] ?: '(555) 000-0000';
    $email = $business['email'] ?: 'contact@example.com';
    $tagline = "{$category} Serving {$city} & Surrounding Areas";

    $heroImage = $customImages['hero'] ?? "https://source.unsplash.com/1600x900/?" . urlencode($category);
    $aboutImage = $customImages['about'] ?? "https://source.unsplash.com/800x600/?" . urlencode($category . ',team');
    $galleryImages = $customImages['gallery'] ?? array_map(
        fn($i) => "https://source.unsplash.com/600x450/?" . urlencode($category) . "&sig={$i}",
        range(1, 6)
    );

    $css = build_site_css($t);
    $nav = build_nav($name);
    $footer = build_footer($name, $phone, $email);
    $editorAssets = build_editor_assets_tag();

    $pages = [];
    $pages['index.html'] = wrap_page($name, 'Home', $css, $nav, build_home_page($name, $tagline, $category, $city, $heroImage, $t), $footer, $editorAssets);
    $pages['about.html'] = wrap_page($name, 'About', $css, $nav, build_about_page($name, $category, $city, $aboutImage, $t), $footer, $editorAssets);
    $pages['services.html'] = wrap_page($name, 'Services', $css, $nav, build_services_page($name, $category, $t), $footer, $editorAssets);
    $pages['gallery.html'] = wrap_page($name, 'Gallery', $css, $nav, build_gallery_page($name, $galleryImages, $t), $footer, $editorAssets);
    $pages['contact.html'] = wrap_page($name, 'Contact', $css, $nav, build_contact_page($name, $phone, $email, $city, $t), $footer, $editorAssets);

    return $pages;
}

/**
 * Placeholder tag replaced with the actual <script> reference to the
 * editor runtime when a page is loaded inside the editor iframe. Left as
 * an HTML comment (invisible, harmless) on normal/published/exported
 * copies of the site so downloaded ZIPs stay 100% clean static HTML.
 */
function build_editor_assets_tag(): string
{
    return '<!--EDITOR_ASSETS-->';
}

function wrap_page(string $businessName, string $pageTitle, string $css, string $nav, string $body, string $footer, string $editorAssets = ''): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$pageTitle} — {$businessName}</title>
<style>{$css}</style>
</head>
<body>
{$nav}
{$body}
{$footer}
{$editorAssets}
</body>
</html>
HTML;
}

function build_site_css(array $t): string
{
    $fontImport = $t['font_url'] ? "@import url('{$t['font_url']}');" : '';
    $dark = $t['dark'] ?? false;
    $bodyBg = $dark ? $t['secondary'] : '#FFFFFF';
    $bodyText = $t['text'];

    return <<<CSS
{$fontImport}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:{$t['font']};background:{$bodyBg};color:{$bodyText};line-height:1.6;}
a{text-decoration:none;color:inherit;}
img{max-width:100%;display:block;}
.container{max-width:1100px;margin:0 auto;padding:0 24px;}
.nav{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;
background:{$t['secondary']};color:#fff;}
.nav a{color:#fff;font-weight:600;}
.nav .brand{font-size:1.3rem;font-weight:800;}
.nav .links{display:flex;gap:24px;font-size:0.9rem;}
.nav .links a:hover{color:{$t['primary']};}
.hero{background:linear-gradient(135deg, {$t['secondary']}CC, {$t['secondary']}EE), url('%HERO_IMAGE%') center/cover;padding:120px 24px;text-align:center;color:#fff;}
.hero h1{font-size:3rem;margin-bottom:16px;max-width:800px;margin-left:auto;margin-right:auto;}
.hero p{font-size:1.2rem;opacity:0.9;max-width:600px;margin:0 auto 32px;}
.btn{display:inline-block;background:{$t['primary']};color:#fff;padding:14px 36px;border-radius:{$t['radius']};font-weight:700;transition:transform 0.2s;}
.btn:hover{transform:translateY(-2px);}
.btn-outline{border:2px solid {$t['primary']};color:{$t['primary']};background:transparent;}
section{padding:80px 24px;}
.section-alt{background:{$t['accent']};}
h2{font-size:2.2rem;margin-bottom:16px;}
.grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:32px;margin-top:40px;}
.grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:48px;align-items:center;}
.card{background:#fff;border-radius:{$t['radius']};padding:32px;box-shadow:0 4px 20px rgba(0,0,0,0.06);}
.card i, .card .icon{color:{$t['primary']};font-size:2rem;margin-bottom:16px;display:block;}
.gallery-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-top:40px;}
.gallery-grid img{border-radius:{$t['radius']};aspect-ratio:4/3;object-fit:cover;}
.contact-form input, .contact-form textarea{width:100%;padding:14px;border:1px solid #E2E8F0;border-radius:8px;margin-bottom:16px;font-family:inherit;}
.contact-form button{background:{$t['primary']};color:#fff;border:none;padding:14px 32px;border-radius:{$t['radius']};font-weight:700;cursor:pointer;}
.footer{background:{$t['secondary']};color:#fff;text-align:center;padding:40px 24px;font-size:0.85rem;opacity:0.85;}
.footer a{text-decoration:underline;}
.text-primary{color:{$t['primary']};}
[data-edit-id]{outline:none;}
@media(max-width:640px){.nav .links{display:none;} .hero h1{font-size:2rem;}}
CSS;
}

function build_nav(string $name): string
{
    return <<<HTML
<nav class="nav" data-edit-id="nav">
  <a href="index.html" class="brand" data-edit-id="nav.brand" data-edit-type="text">{$name}</a>
  <div class="links">
    <a href="index.html">Home</a>
    <a href="about.html">About</a>
    <a href="services.html">Services</a>
    <a href="gallery.html">Gallery</a>
    <a href="contact.html">Contact</a>
  </div>
</nav>
HTML;
}

function build_footer(string $name, string $phone, string $email): string
{
    $year = date('Y');
    return <<<HTML
<footer class="footer" data-edit-id="footer">
  <p data-edit-id="footer.copyright" data-edit-type="text">&copy; {$year} {$name}. All rights reserved.</p>
  <p data-edit-id="footer.contact" data-edit-type="text">{$phone} &middot; <a href="mailto:{$email}">{$email}</a></p>
</footer>
HTML;
}

function build_home_page(string $name, string $tagline, string $category, string $city, string $heroImage, array $t): string
{
    return <<<HTML
<section class="hero" data-edit-id="home.hero" data-edit-bg="1" data-sortable-section="1" style="background:linear-gradient(135deg, {$t['secondary']}CC, {$t['secondary']}EE), url('{$heroImage}') center/cover;">
  <h1 data-edit-id="home.hero.title" data-edit-type="text">{$name}</h1>
  <p data-edit-id="home.hero.subtitle" data-edit-type="text">{$tagline}</p>
  <a href="contact.html" class="btn" data-edit-id="home.hero.cta" data-edit-type="text">Get a Free Quote</a>
</section>

<section data-edit-id="home.why" data-sortable-section="1">
  <div class="container">
    <h2 style="text-align:center;" data-edit-id="home.why.title" data-edit-type="text">Why Choose {$name}?</h2>
    <div class="grid-3">
      <div class="card" data-edit-id="home.why.card1"><span class="icon">&#9733;</span><h3 data-edit-id="home.why.card1.title" data-edit-type="text">Top Rated</h3><p data-edit-id="home.why.card1.body" data-edit-type="text">Trusted by the {$city} community for reliable, high-quality {$category} work.</p></div>
      <div class="card" data-edit-id="home.why.card2"><span class="icon">&#9201;</span><h3 data-edit-id="home.why.card2.title" data-edit-type="text">Fast Response</h3><p data-edit-id="home.why.card2.body" data-edit-type="text">We show up on time and get the job done right, the first time.</p></div>
      <div class="card" data-edit-id="home.why.card3"><span class="icon">&#128176;</span><h3 data-edit-id="home.why.card3.title" data-edit-type="text">Fair Pricing</h3><p data-edit-id="home.why.card3.body" data-edit-type="text">Transparent quotes with no hidden fees, ever.</p></div>
    </div>
  </div>
</section>

<section class="section-alt" style="text-align:center;" data-edit-id="home.cta" data-edit-bg="1" data-sortable-section="1">
  <div class="container">
    <h2 data-edit-id="home.cta.title" data-edit-type="text">Ready to get started?</h2>
    <p style="margin-bottom:32px;" data-edit-id="home.cta.body" data-edit-type="text">Contact {$name} today for a free, no-obligation quote.</p>
    <a href="contact.html" class="btn" data-edit-id="home.cta.button" data-edit-type="text">Contact Us</a>
  </div>
</section>
HTML;
}

function build_about_page(string $name, string $category, string $city, string $aboutImage, array $t): string
{
    return <<<HTML
<section data-edit-id="about.intro" data-sortable-section="1">
  <div class="container grid-2">
    <div>
      <h2 data-edit-id="about.intro.title" data-edit-type="text">About {$name}</h2>
      <p data-edit-id="about.intro.body1" data-edit-type="text">{$name} is a trusted {$category} proudly serving {$city} and the surrounding area. We've built our reputation on honest work, fair pricing, and treating every customer like family.</p>
      <p style="margin-top:16px;" data-edit-id="about.intro.body2" data-edit-type="text">Whether it's a small job or a major project, our team brings the same level of care and craftsmanship every time.</p>
    </div>
    <img src="{$aboutImage}" alt="About {$name}" data-edit-id="about.intro.image" data-edit-type="image">
  </div>
</section>
<section class="section-alt" data-edit-id="about.values" data-edit-bg="1" data-sortable-section="1">
  <div class="container grid-3">
    <div class="card" data-edit-id="about.values.card1"><h3 data-edit-id="about.values.card1.title" data-edit-type="text">Our Mission</h3><p data-edit-id="about.values.card1.body" data-edit-type="text">Deliver dependable {$category} services that our neighbors in {$city} can trust.</p></div>
    <div class="card" data-edit-id="about.values.card2"><h3 data-edit-id="about.values.card2.title" data-edit-type="text">Our Values</h3><p data-edit-id="about.values.card2.body" data-edit-type="text">Integrity, quality, and respect for every customer and every job.</p></div>
    <div class="card" data-edit-id="about.values.card3"><h3 data-edit-id="about.values.card3.title" data-edit-type="text">Our Team</h3><p data-edit-id="about.values.card3.body" data-edit-type="text">Experienced, licensed, and passionate about doing things right.</p></div>
  </div>
</section>
HTML;
}

function build_services_page(string $name, string $category, array $t): string
{
    $services = [
        ['Standard ' . $category, "Comprehensive {$category} services tailored to your needs."],
        ['Emergency Service', "Fast response when you need it most, day or night."],
        ['Free Consultation', "We'll assess your needs and provide an honest, upfront quote."],
        ['Maintenance Plans', "Keep things running smoothly with scheduled upkeep."],
        ['Custom Solutions', "Every job is different — we tailor our approach to fit."],
        ['Licensed & Insured', "Work with confidence knowing you're fully protected."],
    ];
    $cards = '';
    foreach ($services as $i => $s) {
        $n = $i + 1;
        $cards .= "<div class=\"card\" data-edit-id=\"services.card{$n}\"><h3 data-edit-id=\"services.card{$n}.title\" data-edit-type=\"text\">{$s[0]}</h3><p data-edit-id=\"services.card{$n}.body\" data-edit-type=\"text\">{$s[1]}</p></div>";
    }
    return <<<HTML
<section data-edit-id="services.intro" data-sortable-section="1">
  <div class="container">
    <h2 style="text-align:center;" data-edit-id="services.intro.title" data-edit-type="text">Our Services</h2>
    <p style="text-align:center;max-width:600px;margin:0 auto;" data-edit-id="services.intro.body" data-edit-type="text">Everything {$name} offers, all backed by our commitment to quality.</p>
    <div class="grid-3">{$cards}</div>
  </div>
</section>
HTML;
}

function build_gallery_page(string $name, array $images, array $t): string
{
    $imgTags = '';
    foreach ($images as $i => $img) {
        $n = $i + 1;
        $imgTags .= "<img src=\"{$img}\" alt=\"{$name} project photo\" data-edit-id=\"gallery.image{$n}\" data-edit-type=\"image\">";
    }
    return <<<HTML
<section data-edit-id="gallery.intro" data-sortable-section="1">
  <div class="container">
    <h2 style="text-align:center;" data-edit-id="gallery.intro.title" data-edit-type="text">Our Work</h2>
    <p style="text-align:center;" data-edit-id="gallery.intro.body" data-edit-type="text">A look at recent projects completed by {$name}.</p>
    <div class="gallery-grid">{$imgTags}</div>
  </div>
</section>
HTML;
}

function build_contact_page(string $name, string $phone, string $email, string $city, array $t): string
{
    return <<<HTML
<section data-edit-id="contact.main" data-sortable-section="1">
  <div class="container grid-2">
    <div>
      <h2 data-edit-id="contact.main.title" data-edit-type="text">Get In Touch</h2>
      <p data-edit-id="contact.main.body" data-edit-type="text">Have a question or ready for a quote? Reach out to {$name} — we serve {$city} and the surrounding area.</p>
      <p style="margin-top:24px;"><strong>Phone:</strong> <a href="tel:{$phone}" class="text-primary" data-edit-id="contact.main.phone" data-edit-type="text">{$phone}</a></p>
      <p><strong>Email:</strong> <a href="mailto:{$email}" class="text-primary" data-edit-id="contact.main.email" data-edit-type="text">{$email}</a></p>
      <a href="tel:{$phone}" class="btn" style="margin-top:24px;" data-edit-id="contact.main.cta" data-edit-type="text">Call Now</a>
    </div>
    <form class="contact-form" onsubmit="alert('Thanks! We will be in touch soon.'); return false;">
      <input type="text" placeholder="Your Name" required>
      <input type="email" placeholder="Your Email" required>
      <input type="tel" placeholder="Your Phone">
      <textarea placeholder="How can we help?" rows="4" required></textarea>
      <button type="submit">Send Message</button>
    </form>
  </div>
</section>
HTML;
}
