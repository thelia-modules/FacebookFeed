<?php

namespace FacebookFeed\Service;

use FacebookFeed\FacebookFeed;
use Propel\Runtime\Propel;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\Base\CountryQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Country;
use Thelia\Model\Currency;
use Thelia\Model\LangQuery;
use Thelia\Model\ProductQuery;
use Thelia\TaxEngine\Calculator;
use Thelia\Tools\URL;

class FacebookFeedService
{
    public function exportFacebookFeed(?int $limit = null, ?int $offset = null,?OutputInterface $output = null) :string
    {
        $dirXml = FacebookFeed::EXPORT_DIR;

        if (!is_dir($dirXml)) {
            mkdir($dirXml);
        }

        $fileName = $dirXml.DS.'fluxfacebook.csv';
        $csvFile = fopen($fileName, 'w');

        $currency = Currency::getDefaultCurrency();
        $country = $this->getDefaultCountry();
        $baseUrl = ConfigQuery::read('url_site');

        $productItems = $this->getProductItems($limit,$offset);
        if ($output){
            $progressBar = new ProgressBar($output, count($productItems));
        }
        $header = ['id','title','description','availability','condition','price','link','image_link','brand',
            'quantity_to_sell_on_facebook','sale_price','color','size'];
        fputcsv($csvFile, $header,';');

        foreach ($productItems as $productSaleElement) {
            if (FacebookFeed::getConfigValue(FacebookFeed::HAS_STOCK,null) === '1' && $productSaleElement['QUANTITY'] < 1){
                continue;
            }

            $productModel = ProductQuery::create()->findOneById($productSaleElement['ID_PRODUCT']);
            $data = [];
            $data[] = $productSaleElement['REF_PRODUCT'];
            $data[] = substr($productSaleElement['TITLE'], 0, 150);

            $data[] = htmlspecialchars(html_entity_decode(trim(strip_tags(substr($productSaleElement['DESCRIPTION'], 0, 9999)))), ENT_XML1);

            $availability = 'out of stock';
            if ($productSaleElement['QUANTITY'] > 0) {
                $availability = 'in stock';
            }
            $data[] = $availability;

            $data[] = 'new';
            $calculator = $this->getTaxCalculator($productModel->getTaxRule(), $productModel, $country);
            $price = $calculator->getTaxedPrice($productSaleElement['PRICE']);
            $data[] = round(doubleval($price), 2) . " " . $currency->getCode();

            $data[] = $this->getUrl($productSaleElement);
            $data[] = $baseUrl . '/cache/images/product/' . $productSaleElement['IMAGE_NAME'];
            $data[] = $productSaleElement['BRAND_TITLE'];

            $data[] = $productSaleElement['QUANTITY'];


            $pricePromo = '';
            if ($productSaleElement['PROMO'] === 1) {
                $price = $calculator->getTaxedPrice($productSaleElement['PROMO_PRICE']);
                $pricePromo = round(doubleval($price), 2) . " " . $currency->getCode();
            }
            $data[] = $pricePromo;

            $color = '';
            $colorAttributeIds = FacebookFeed::getConfigValue(FacebookFeed::ATTRIBUTE_COLOR_ID,null);
            if ($colorAttributeIds){
                $color = $this->getAttributeAvTitle($productSaleElement['ID'], explode(',',$colorAttributeIds));
            }
            $data[] = $color;

            $sizeAttributeIds = FacebookFeed::getConfigValue(FacebookFeed::ATTRIBUTE_SIZE_ID,null);
            $size = '';
            if ($sizeAttributeIds){
                $size = $this->getAttributeAvTitle($productSaleElement['ID'], explode(',',$sizeAttributeIds));
            }
            $data[] = $size;

            fputcsv($csvFile,$data,';');
            if ($output){
                $progressBar->advance();
            }
        }
        if ($output){
            $progressBar->finish();
        }
        fclose($csvFile);
        return $fileName;
    }


    private function getAttributeAvTitle(int $pseId, array $attributeIds): ?string
    {
        $attribute = '';
        $attributeCombinations = AttributeCombinationQuery::create()
            ->filterByAttributeId($attributeIds)
            ->filterByProductSaleElementsId($pseId)
            ->find();

        foreach ($attributeCombinations as $attributeCombination) {
            if (!$attribute) {
                $attribute = $attributeCombination?->getAttributeAv()?->setLocale('fr_FR')->getTitle();
                continue;
            }
            $attribute .= ',' . $attributeCombination?->getAttributeAv()?->setLocale('fr_FR')->getTitle();
        }
        return $attribute;
    }

    protected function getProductItems(int $limit = null, int $offset = null): array|false
    {
        $sql = "SELECT 

                pse.ID AS ID,
                pse.WEIGHT AS WEIGHT,
                pse.PROMO AS PROMO,
                product.ID AS ID_PRODUCT,
                product.REF AS REF_PRODUCT,
                product.VISIBLE AS PRODUCT_VISIBLE,
                product_i18n.TITLE AS TITLE,
                product_i18n.CHAPO AS CHAPO,
                product_i18n.DESCRIPTION AS DESCRIPTION,
                COALESCE (brand_i18n_with_locale.TITLE, brand_i18n_without_locale.TITLE) AS BRAND_TITLE,
                pse.QUANTITY AS QUANTITY,
                pse.EAN_CODE AS EAN_CODE,
                product_category.CATEGORY_ID AS CATEGORY_ID,
                product.TAX_RULE_ID AS TAX_RULE_ID,
                COALESCE(price_on_currency.PRICE, CASE WHEN NOT ISNULL(price_default.PRICE) THEN ROUND(price_default.PRICE * :currate, 2) END) AS PRICE,
                COALESCE(price_on_currency.PROMO_PRICE, CASE WHEN NOT ISNULL(price_default.PROMO_PRICE) THEN ROUND(price_default.PROMO_PRICE * :currate, 2) END) AS PROMO_PRICE,
                rewriting_url.URL AS REWRITTEN_URL,
                COALESCE(product_image_on_pse.FILE, product_image_default.FILE) AS IMAGE_NAME
                
                FROM product_sale_elements AS pse
                
                INNER JOIN product ON (pse.PRODUCT_ID = product.ID) AND product.VISIBLE = 1
                LEFT OUTER JOIN product_price price_on_currency ON (pse.ID = price_on_currency.PRODUCT_SALE_ELEMENTS_ID AND price_on_currency.CURRENCY_ID = :currid)
                LEFT OUTER JOIN product_price price_default ON (pse.ID = price_default.PRODUCT_SALE_ELEMENTS_ID AND price_default.FROM_DEFAULT_CURRENCY = 1)
                LEFT OUTER JOIN product_category ON (pse.PRODUCT_ID = product_category.PRODUCT_ID AND product_category.DEFAULT_CATEGORY = 1)
                LEFT OUTER JOIN product_i18n ON (pse.PRODUCT_ID = product_i18n.ID AND product_i18n.LOCALE = :locale)
                LEFT OUTER JOIN brand_i18n brand_i18n_with_locale ON (product.BRAND_ID = brand_i18n_with_locale.ID AND brand_i18n_with_locale.LOCALE = :locale)
                LEFT OUTER JOIN brand_i18n brand_i18n_without_locale ON (product.BRAND_ID = brand_i18n_without_locale.ID)
                LEFT OUTER JOIN rewriting_url ON (pse.PRODUCT_ID = rewriting_url.VIEW_ID AND rewriting_url.view = 'product' AND rewriting_url.view_locale = :locale AND rewriting_url.redirected IS NULL)
                LEFT OUTER JOIN product_sale_elements_product_image pse_image ON (pse.ID = pse_image.PRODUCT_SALE_ELEMENTS_ID)
                LEFT OUTER JOIN product_image product_image_default ON (pse.PRODUCT_ID = product_image_default.PRODUCT_ID AND product_image_default.POSITION = 1)
                LEFT OUTER JOIN product_image product_image_on_pse ON (product_image_on_pse.ID = pse_image.PRODUCT_IMAGE_ID)
                
                GROUP BY pse.ID";

        $limit = $this->checkPositiveInteger($limit);
        $offset = $this->checkPositiveInteger($offset);

        if ($limit) {
            $sql .= " LIMIT $limit";
        }

        if ($offset) {
            if (!$limit) {
                $sql .= " LIMIT 99999999999";
            }
            $sql .= " OFFSET $offset";
        }

        $con = Propel::getConnection();
        $stmt = $con->prepare($sql);
        $stmt->bindValue(':locale', $this->getDefaultLang()->getLocale(), \PDO::PARAM_STR);
        $stmt->bindValue(':currid', $this->getDefaultCurrency()->getId(), \PDO::PARAM_INT);
        $stmt->bindValue(':currate', $this->getDefaultCurrency()->getRate(), \PDO::PARAM_STR);

        $stmt->execute();
        $pseArray = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $pseArray;
    }

    private function checkPositiveInteger($var): mixed
    {
        $var = filter_var($var, FILTER_VALIDATE_INT);
        return ($var !== false && $var >= 0) ? $var : null;
    }

    private function getDefaultLang(): ?\Thelia\Model\Lang
    {
        return LangQuery::create()->filterByByDefault(1)->findOne();
    }

    private function getDefaultCurrency(): ?Currency
    {
        return Currency::getDefaultCurrency();
    }

    private function getDefaultCountry()
    {
        return CountryQuery::create()->filterByIsoalpha3('FRA')->findOne();
    }


    private function getTaxCalculator($taxRule, $product, $taxedCountry): Calculator
    {
        $taxCalculator = new Calculator();

        $country = null;

        //Fix for thelia <= 2.4.0
        if (isset($taxedCountries[0])) {
            $country = CountryQuery::create()->findOneById($taxedCountry->getId());
        }

        if (null === $country) {
            $country = Country::getDefaultCountry();
        }

        $taxCalculator->loadTaxRule($taxRule, $country, $product);

        return $taxCalculator;
    }

    private function getUrl($product): string
    {
        $attributeAvID = AttributeCombinationQuery::create()
            ->filterByProductSaleElementsId($product["ID"])
            ->findOne();

        $urlManager = URL::getInstance();

        $url = null;
        if ($product['REWRITTEN_URL'] === null) {
            $url = $urlManager->retrieve('product', $product['ID_PRODUCT'], $this->getDefaultLang()->getLocale())->toString();
        } else {
            $url = $urlManager->absoluteUrl($product['REWRITTEN_URL']);
        }
        return $url;
    }
}