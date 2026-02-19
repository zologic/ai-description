<?php
defined('ABSPATH') || exit;

/**
 * APDG_Prompt_Builder
 *
 * Responsible only for constructing prompts.
 * No API calls, no model selection, no response parsing.
 */
class APDG_Prompt_Builder {

    // 12 heading pools — rotated randomly to reduce Google footprint detection at 47k+ SKUs
    private const HEADING_POOLS = [
        ['Productomschrijving',  'Belangrijkste kenmerken',       'Geschikt voor'],
        ['Over dit product',     'Details',                       'Gebruik'],
        ['Kenmerken',            'Materiaal & Samenstelling',      'Toepassing'],
        ['Beschrijving',         'Specificaties',                  'Voor wie'],
        ['Productinformatie',    'Wat maakt dit product bijzonder','Gebruiksadvies'],
        ['Algemene informatie',  'Eigenschappen',                  'Toepassingsgebied'],
        ['Over dit artikel',     'Inhoud & Samenstelling',         'Praktische informatie'],
        ['Productdetails',       'Functie & Voordelen',            'Aanbevolen gebruik'],
        ['Wat is dit product',   'Technische kenmerken',           'Doelgroep'],
        ['Korte toelichting',    'Kenmerken op een rij',           'Hoe te gebruiken'],
        ['Productbeschrijving',  'Inhoud & formaat',               'Waarvoor geschikt'],
        ['Introductie',          'Wat je moet weten',              'Gebruik & verzorging'],
    ];

    private const TIER_CONFIG = [
        'high' => ['min' => 350, 'max' => 550],
        'mid'  => ['min' => 200, 'max' => 350],
        'low'  => ['min' => 120, 'max' => 180],
    ];

    private const SYSTEM_PROMPT = 'Je bent een ecommerce copywriter voor een Nederlandse WooCommerce webshop.

TAALREGEL: Schrijf uitsluitend in correct, natuurlijk Nederlands. Meng geen Engelse woorden tenzij dit deel uitmaakt van de officiële productnaam. Gebruik een professionele, neutrale ecommerce toon.

INHOUDSREGELS:
1. Gebruik uitsluitend informatie uit de aangeleverde productdata.
2. Voeg geen ingrediënten, voordelen, materialen of eigenschappen toe die niet expliciet zijn vermeld.
3. Gebruik geen medische of therapeutische claims.
4. Verboden termen: "klinisch bewezen", "garandeert", "geneest", "beste", "nummer 1", "100% effectief", "anti-aging werking", "dermatologisch getest", "vermindert rimpels", "behandelt acne", "herstelt beschadigde huid", "voorkomt haaruitval", "koop nu", "beste prijs", "vandaag besteld", "op voorraad".
5. Geen overdreven marketingtaal. Geen emoji\'s.
6. Schrijf informatief en betrouwbaar.
7. Varieer zinsopbouw en vermijd herhalende standaardzinnen.
8. Als productdata beperkt is, beschrijf het product op categorieniveau zonder details te verzinnen.

OUTPUT: BELANGRIJK: Geef ALLEEN geldige JSON terug. Geen markdown code blokken (geen ``` tekens). Geen uitleg of tekst buiten de JSON. Begin direct met { en eindig met }. Zorg dat alle tekst binnen strings correct is afgesloten met dubbele quotes.';

    public static function system(): string {
        return self::SYSTEM_PROMPT;
    }

    public static function user(array $product_data, string $tier, string $mode): string {
        $cfg      = self::TIER_CONFIG[$tier] ?? self::TIER_CONFIG['mid'];
        $headings = self::HEADING_POOLS[array_rand(self::HEADING_POOLS)];
        $h_list   = implode(', ', array_map(fn($h) => "<h3>{$h}</h3>", $headings));

        $title  = $product_data['title']      ?? '';
        $brand  = $product_data['brand']      ?: 'onbekend';
        $cat    = $product_data['category']   ?? 'Algemeen';
        $subcat = $product_data['subcategory'] ?? '';
        $attrs  = !empty($product_data['attributes']) ? implode(', ', $product_data['attributes']) : 'niet opgegeven';

        $output = match($mode) {
            'meta_only'  => '{ "meta_description": "VEREIST: 110–155 tekens. Neutraal informatief, bevat primair zoekwoord uit de titel eenmaal. Geen CTA-taal." }',
            'short_only' => '{ "short_description": "VEREIST: 40–90 woorden. 1 inleidende zin + 3–5 <ul><li> bulletpoints met neutrale producteigenschappen. Geen claims." }',
            default      => '{
  "short_description": "VEREIST: 40–90 woorden. 1 inleidende zin + 3–5 <ul><li> bulletpoints.",
  "long_description": "Volledige HTML-beschrijving. Gebruik exact deze ' . count($headings) . ' <h3>-koppen in volgorde: ' . $h_list . '. MINIMAAL ' . $cfg['min'] . ' woorden VEREIST, ideaal ' . $cfg['max'] . ' woorden. Gebruik <ul><li> voor lijsten. Vul elke sectie met voldoende detail.",
  "meta_description": "VEREIST: 110–155 tekens. Neutraal. Patroon: [Merk] [Producttype] – neutrale samenvatting. Geen CTA."
}',
        };

        return "Productgegevens:\nTitel: {$title}\nMerk: {$brand}\nCategorie: {$cat}" .
               ($subcat ? " > {$subcat}" : '') .
               "\nKenmerken: {$attrs}\n\n" .
               "Instructies:\n- Integreer merk en categorie logisch in de tekst.\n" .
               "- Verwerk kenmerken alleen indien expliciet aanwezig.\n" .
               "- Als kenmerken ontbreken, blijf algemeen binnen de categorie.\n" .
               "- Houd de toon professioneel en helder.\n" .
               "- BELANGRIJK: Voldoe aan alle minimum lengte-eisen (woorden/tekens).\n\n" .
               "Genereer exact:\n{$output}";
    }

    /**
     * Extracts product data array from a WC_Product object.
     * Keeps this logic out of both Generator and Ajax handlers.
     */
    public static function extract_product_data(WC_Product $product): array {
        $pid  = $product->get_id();
        $cats = wp_get_post_terms($pid, 'product_cat', ['fields' => 'names']);

        $attrs = [];
        foreach ($product->get_attributes() as $key => $attr) {
            $val = is_object($attr)
                ? implode(', ', wc_get_product_terms($pid, $attr->get_name(), ['fields' => 'names']))
                : (string) $attr;
            if (!empty($val)) {
                $attrs[] = ucfirst(wc_attribute_label($key)) . ': ' . $val;
            }
        }

        return [
            'title'       => $product->get_name(),
            'category'    => $cats[0] ?? 'Algemeen',
            'subcategory' => $cats[1] ?? '',
            'brand'       => get_post_meta($pid, '_apdg_brand', true)
                             ?: $product->get_attribute('merk')
                             ?: $product->get_attribute('brand')
                             ?: '',
            'attributes'  => $attrs,
        ];
    }
}
