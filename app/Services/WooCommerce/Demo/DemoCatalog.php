<?php

namespace App\Services\WooCommerce\Demo;

use App\Models\WooCommerceStore;

/**
 * The fictional sample catalog. Records use WooCommerce's REST shape, so the normal
 * mapper reads them, and the fixed dates keep the demo repeatable.
 *
 * IDs start at 900101 to stay visibly apart from the IDs of a typical real store.
 */
final class DemoCatalog
{
    private const CREATED_GMT = '2026-09-02T08:15:00';

    /**
     * @return list<array<string, mixed>>
     */
    public static function products(): array
    {
        return [
            self::product(900101, 'Aurora RGB Mouse Pad', 'Gaming Accessories', 'DEMO-GM-101', '24.99', 38, [
                'date_modified_gmt' => '2026-10-14T09:30:00',
                'description' => '<p>An extended cloth mouse pad with a stitched edge and a soft lighting strip around the border.</p><p>The rubber base keeps it in place during fast movement.</p>',
                'short_description' => '<p>Extended cloth mouse pad with edge lighting.</p>',
                'images' => [self::image(1, 'aurora-mouse-pad-front.jpg'), self::image(2, 'aurora-mouse-pad-lit.jpg')],
                'meta_data' => self::seo('Aurora RGB Mouse Pad | Demo Store', 'Extended RGB mouse pad with stitched edges and a non-slip base.'),
            ]),
            self::product(900102, 'Titan Headset Stand', 'Gaming Accessories', 'DEMO-GM-102', '32.50', 12, [
                'date_modified_gmt' => '2026-10-02T15:05:00',
                'description' => '<p>Good stand.</p>',
                'short_description' => '',
            ]),
            self::product(900103, 'Pixel Hex Wall Light', 'Home Decor', 'DEMO-HD-103', '44.00', null, [
                'type' => 'variable',
                'date_modified_gmt' => '2026-10-20T11:42:00',
                'regular_price' => '',
                'description' => '<p>Modular hexagonal wall panels that connect edge to edge, so a layout can grow over time.</p>',
                'short_description' => '<p>Modular hexagon wall lights.</p>',
                'images' => [self::image(3, 'pixel-hex-wall.jpg')],
                'attributes' => [['id' => 1, 'name' => 'Pack size', 'variation' => true, 'options' => ['3 panels', '6 panels', '9 panels']]],
                'variations' => [900201, 900202, 900203],
            ]),
            self::product(900104, 'Walnut Floating Shelf', 'Home Decor', '', '58.00', 5, [
                'status' => 'draft',
                'date_modified_gmt' => '2026-09-18T07:20:00',
                'description' => '',
                'short_description' => '',
            ]),
            self::product(900105, 'Engraved Oak Photo Frame', 'Personalized Gifts', 'DEMO-PG-105', '36.00', 21, [
                'date_modified_gmt' => '2026-10-11T13:10:00',
                'description' => '<p>A solid oak frame for a 13 x 18 cm print, engraved with a name and date of your choice.</p>',
                'short_description' => '<p>Solid oak frame with custom engraving.</p>',
                'images' => [self::image(4, 'oak-frame-small.jpg')],
                'meta_data' => self::seo('Engraved Oak Photo Frame', 'Solid oak photo frame engraved with a name and date.'),
            ]),
            self::product(900106, 'Engraved Oak Photo Frame', 'Personalized Gifts', 'DEMO-PG-106', '49.00', 9, [
                'status' => 'pending',
                'slug' => 'engraved-oak-photo-frame-2',
                'date_modified_gmt' => '2026-10-12T10:00:00',
                'description' => '<p>Large size.</p>',
                'short_description' => '<p>Large frame.</p>',
                'images' => [self::image(5, 'oak-frame-large.jpg')],
            ]),
            self::product(900107, 'Custom Name Keepsake Box', 'Personalized Gifts', 'DEMO-PG-107', '41.25', 14, [
                'status' => 'private',
                'date_modified_gmt' => '2026-09-29T16:45:00',
                'description' => '<p>Nice box for gifts.</p>',
                'short_description' => '',
            ]),
            self::product(900108, 'Bamboo Desk Organizer', 'Office Accessories', 'DEMO-OF-108', '27.90', 44, [
                'date_modified_gmt' => '2026-10-05T08:55:00',
                'description' => '',
                'short_description' => '<p>Desk organizer.</p>',
                'images' => [self::image(6, 'bamboo-organizer.jpg')],
            ]),
            self::product(900109, 'Felt Desk Mat', 'Office Accessories', 'DEMO-OF-109', '29.00', 30, [
                'date_modified_gmt' => '2026-10-16T12:30:00',
                'sale_price' => '25.00',
                'price' => '25.00',
                'regular_price' => '29.00',
                'description' => '<p>A dense wool-blend felt mat that quiets the keyboard and protects the desk surface.</p>',
                'short_description' => '<p>Wool-blend felt mat for quiet desks.</p>',
                'images' => [self::image(7, 'felt-desk-mat.jpg')],
            ]),
            self::product(900110, 'Slim Clear Phone Case', 'Phone Accessories', '', '14.99', 120, [
                'date_modified_gmt' => '2026-10-08T09:12:00',
                'description' => '<p>Clear case.</p>',
                'short_description' => '',
            ]),
            self::product(900111, 'Magnetic Car Phone Mount', 'Phone Accessories', 'DEMO-PH-111', '22.00', 0, [
                'date_modified_gmt' => '2026-10-19T17:25:00',
                'stock_status' => 'outofstock',
                'description' => '<p>Clips to a car air vent and holds a phone with a strong magnetic ring.</p>',
                'short_description' => '<p>Magnetic air vent phone mount.</p>',
                'images' => [self::image(8, 'car-mount.jpg')],
                'meta_data' => self::seo('Magnetic Car Phone Mount', 'Air vent phone mount with a strong magnetic hold.'),
            ]),
            self::product(900112, 'Brass Compass Keychain', 'Keychains', 'DEMO-KC-112', '12.50', 64, [
                'status' => 'draft',
                'date_modified_gmt' => '2026-09-25T14:00:00',
                'description' => '',
                'short_description' => '',
            ]),
            self::product(900113, 'Desk Starter Bundle', 'Office Accessories', 'DEMO-OF-113', '', null, [
                'type' => 'grouped',
                'date_modified_gmt' => '2026-10-21T08:00:00',
                'regular_price' => '',
                'description' => '<p>A grouped listing of desk essentials.</p>',
                'short_description' => '',
                'grouped_products' => [900108, 900109],
            ]),

            // Deliberately unusable, so the import shows how a bad record is reported.
            self::product(900114, '', 'Keychains', 'DEMO-KC-114', '9.00', 3, [
                'status' => 'draft',
                'date_modified_gmt' => '2026-09-30T10:00:00',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $remoteId): ?array
    {
        return collect(self::products())->firstWhere('id', $remoteId);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function product(int $id, string $name, string $category, string $sku, string $price, ?int $stock, array $overrides): array
    {
        $slug = $overrides['slug'] ?? str($name)->slug()->toString();

        return array_replace([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'permalink' => WooCommerceStore::DEMO_URL.'/product/'.$slug,
            'date_created_gmt' => self::CREATED_GMT,
            'type' => 'simple',
            'status' => 'publish',
            'description' => '',
            'short_description' => '',
            'sku' => $sku,
            'price' => $price,
            'regular_price' => $price,
            'sale_price' => '',
            'manage_stock' => $stock !== null,
            'stock_quantity' => $stock,
            'stock_status' => 'instock',
            'weight' => '0.45',
            'dimensions' => ['length' => '20', 'width' => '15', 'height' => '4'],
            'shipping_class' => 'standard',
            'tax_status' => 'taxable',
            'tax_class' => 'standard',
            'categories' => [['id' => crc32($category) % 1000, 'name' => $category, 'slug' => str($category)->slug()->toString()]],
            'images' => [],
            'attributes' => [],
            'variations' => [],
            'meta_data' => [],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function image(int $id, string $file): array
    {
        return ['id' => $id, 'src' => WooCommerceStore::DEMO_URL.'/images/'.$file, 'name' => $file, 'alt' => ''];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function seo(string $title, string $description): array
    {
        return [
            ['id' => 1, 'key' => '_yoast_wpseo_title', 'value' => $title],
            ['id' => 2, 'key' => '_yoast_wpseo_metadesc', 'value' => $description],
        ];
    }
}
