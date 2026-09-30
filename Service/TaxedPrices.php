<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FacebookFeed\Service;

use Propel\Runtime\Propel;
use Symfony\Contracts\Service\ResetInterface;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactoryInterface;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorInterface;
use Thelia\Model\Country;
use Thelia\Model\ProductQuery;
use Thelia\Model\TaxRule;
use Thelia\Model\TaxRuleQuery;

/**
 * Adds the taxes of the default country to a price.
 *
 * A tax rule is loaded once for the whole feed, not once per product: only a rule holding a
 * tax that reads the product itself (an amount taken from a feature) is loaded per product.
 */
final class TaxedPrices implements ResetInterface
{
    /** @var array<int, TaxCalculatorInterface> by tax rule id */
    private array $calculatorsByTaxRule = [];

    /** @var array<int, bool> by tax rule id */
    private array $readsTheProduct = [];

    /** @var array<int, TaxCalculatorInterface> by product id */
    private array $calculatorsByProduct = [];

    public function __construct(private readonly TaxCalculatorFactoryInterface $taxCalculatorFactory)
    {
    }

    public function reset(): void
    {
        $this->calculatorsByTaxRule = [];
        $this->readsTheProduct = [];
        $this->calculatorsByProduct = [];
    }

    public function taxedPrice(float $untaxedPrice, int $taxRuleId, int $productId): float
    {
        return (float) $this->calculator($taxRuleId, $productId)->getTaxedPrice($untaxedPrice);
    }

    private function calculator(int $taxRuleId, int $productId): TaxCalculatorInterface
    {
        if (!$this->readsTheProduct($taxRuleId)) {
            return $this->calculatorsByTaxRule[$taxRuleId] ??= $this->loadedWithoutProduct($taxRuleId);
        }

        return $this->calculatorsByProduct[$productId] ??= $this->loadedForProduct($taxRuleId, $productId);
    }

    private function loadedWithoutProduct(int $taxRuleId): TaxCalculatorInterface
    {
        return $this->taxCalculatorFactory->createTaxCalculator()->loadTaxRuleWithoutProduct(
            $this->taxRule($taxRuleId),
            Country::getDefaultCountry(),
        );
    }

    private function loadedForProduct(int $taxRuleId, int $productId): TaxCalculatorInterface
    {
        $product = ProductQuery::create()->findPk($productId);
        if (null === $product) {
            throw new \LogicException(\sprintf('The product %d does not exist.', $productId));
        }

        return $this->taxCalculatorFactory->createTaxCalculator()->loadTaxRule(
            $this->taxRule($taxRuleId),
            Country::getDefaultCountry(),
            $product,
        );
    }

    private function taxRule(int $taxRuleId): TaxRule
    {
        $taxRule = TaxRuleQuery::create()->findPk($taxRuleId);
        if (null === $taxRule) {
            throw new \LogicException(\sprintf('The tax rule %d does not exist.', $taxRuleId));
        }

        return $taxRule;
    }

    private function readsTheProduct(int $taxRuleId): bool
    {
        if (isset($this->readsTheProduct[$taxRuleId])) {
            return $this->readsTheProduct[$taxRuleId];
        }

        $statement = Propel::getConnection()->prepare(
            'SELECT tax.type FROM tax_rule_country AS link INNER JOIN tax ON tax.id = link.tax_id WHERE link.tax_rule_id = ?',
        );
        $statement->bindValue(1, $taxRuleId, \PDO::PARAM_INT);
        $statement->execute();

        $readsTheProduct = false;
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $taxType) {
            $readsTheProduct = $readsTheProduct || str_contains((string) $taxType, 'Feature');
        }

        return $this->readsTheProduct[$taxRuleId] = $readsTheProduct;
    }
}
