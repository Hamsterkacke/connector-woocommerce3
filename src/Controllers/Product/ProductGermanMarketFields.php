<?php
/**
 * @author    Jan Weskamp <jan.weskamp@jtl-software.com>
 * @copyright 2010-2013 JTL-Software GmbH
 */

namespace JtlWooCommerceConnector\Controllers\Product;

use jtl\Connector\Model\Identity;
use jtl\Connector\Model\Product as ProductModel;
use JtlWooCommerceConnector\Controllers\BaseController;
use PhpUnitsOfMeasure\Exception\NonNumericValue;
use PhpUnitsOfMeasure\Exception\NonStringUnitName;
use PhpUnitsOfMeasure\PhysicalQuantity\Area;
use PhpUnitsOfMeasure\PhysicalQuantity\Length;
use PhpUnitsOfMeasure\PhysicalQuantity\Mass;
use PhpUnitsOfMeasure\PhysicalQuantity\Volume;

class ProductGermanMarketFields extends BaseController
{
    /**
     * @param ProductModel $product
     * @param \WC_Product  $wcProduct
     *
     * @throws NonNumericValue
     * @throws NonStringUnitName
     */
    public function pullData(ProductModel &$product, \WC_Product $wcProduct)
    {
        $this->setBasePriceProperties($product, $wcProduct);
        $this->setRRPProperty($product, $wcProduct);
    }
    
    /**
     * @param ProductModel $product
     * @param \WC_Product  $wcProduct
     *
     * @throws \PhpUnitsOfMeasure\Exception\NonNumericValue
     * @throws \PhpUnitsOfMeasure\Exception\NonStringUnitName
     */
    private function setBasePriceProperties(ProductModel &$product, \WC_Product $wcProduct)
    {
        $metaKeys = $this->getGermanMarketMetaKeys($product->getMasterProductId()->getEndpoint() === 0);
        
        if ($this->hasGermanMarketUnitPrice($wcProduct, $metaKeys)) {
            $metaData = $this->getGermanMarketMeta($wcProduct, $metaKeys);
            $metaGroup = $this->identifyGermanMarketMetaGroup($metaData[$metaKeys['unitRegularUnitKey']]);
            
            $price = $metaData[$metaKeys['priceKey']];
            $wcWeightOption = \get_option('woocommerce_weight_unit');
            $wcLengthOption = \get_option('woocommerce_dimension_unit');
            $wcSquareOption = $wcLengthOption . '^2';
            $wcVolumeOption = 'l';
            
            switch ($metaGroup) {
                case 'weight':
                    $weight = $metaData[$metaKeys['weightKey']];
                    $weightUnit = $metaData[$metaKeys['unitRegularUnitKey']];
                    $weightUnitMult = (float)$metaData[$metaKeys['unitRegularMultiplikatorKey']];
                    
                    if ($wcWeightOption !== $weightUnit) {
                        $mass = new Mass($weightUnitMult, $weightUnit);
                        $mass = $mass->toUnit($wcWeightOption);
                        $weightUnitMult = $mass;
                        $weightUnit = $wcWeightOption;
                    }
                    
                    if ($weight === 0 || !$weight || is_null($weight)) {
                        $weight = (float)$weightUnitMult;
                    }
                    
                    $divisor = $weight / $weightUnitMult;
                    $basePrice = $price / $divisor;
                    
                    $baseQuantity = $weightUnitMult;
                    $productQuantity = $weight;
                    $code = $weightUnit;
                    break;
                case 'surface':
                    
                    $length = $metaData[$metaKeys['lengthKey']];
                    $width = $metaData[$metaKeys['widthKey']];
                    $squareResult = $length * $width;
                    
                    $squareUnit = $metaData[$metaKeys['unitRegularUnitKey']];
                    $squareUnit = str_replace('2', '^2', $squareUnit);
                    $squareUnitMult = (float)$metaData[$metaKeys['unitRegularMultiplikatorKey']];
                    
                    if ($wcSquareOption !== $squareUnit) {
                        $square = new Area($squareUnitMult, $squareUnit);
                        $square = $square->toUnit($wcSquareOption);
                        $squareUnitMult = $square;
                        $squareUnit = $wcSquareOption;
                    }
                    
                    if ($squareResult === 0 || !$squareResult || is_null($squareResult)) {
                        $squareResult = (float)$squareUnitMult;
                    }
                    
                    $divisor = $squareResult / $squareUnitMult;
                    $basePrice = $price / $divisor;
                    
                    $baseQuantity = $squareUnitMult;
                    $productQuantity = $squareResult;
                    $code = str_replace('^2', '2', $squareUnit);
                    break;
                case 'length':
                    $length = $metaData[$metaKeys['lengthKey']];
                    $lengthUnit = $metaData[$metaKeys['unitRegularUnitKey']];
                    $lengthUnitMult = (float)$metaData[$metaKeys['unitRegularMultiplikatorKey']];
                    
                    if ($wcLengthOption !== $lengthUnit) {
                        $range = new Length($lengthUnitMult, $lengthUnit);
                        $range = $range->toUnit($wcLengthOption);
                        $lengthUnitMult = $range;
                        $lengthUnit = $wcLengthOption;
                    }
                    
                    if ($length === 0 || !$length || is_null($length)) {
                        $length = (float)$lengthUnitMult;
                    }
                    
                    $divisor = $length / $lengthUnitMult;
                    $basePrice = $price / $divisor;
                    
                    $baseQuantity = $lengthUnitMult;
                    $productQuantity = $length;
                    $code = $lengthUnit;
                    break;
                case 'volume':
                    $length = $metaData[$metaKeys['lengthKey']];
                    $width = $metaData[$metaKeys['widthKey']];
                    $height = $metaData[$metaKeys['heightKey']];
                    
                    $volumeResult = $length * $width * $height;
                    
                    $volumeUnit = $metaData[$metaKeys['unitRegularUnitKey']];
                    $volumeUnit = str_replace('3', '^3', strtolower($volumeUnit));
                    $volumeUnitMult = (float)$metaData[$metaKeys['unitRegularMultiplikatorKey']];
                    
                    if ($wcVolumeOption !== $volumeUnit) {
                        $volume = new Volume($volumeUnitMult, $volumeUnit);
                        $volume = $volume->toUnit($wcVolumeOption);
                        $volumeUnitMult = $volume;
                        $volumeUnit = $wcVolumeOption;
                    }
                    
                    if ($volumeResult === 0 || !$volumeResult || is_null($volumeResult)) {
                        $volumeResult = (float)$volumeUnitMult;
                    }
                    
                    $divisor = $volumeResult / $volumeUnitMult;
                    $basePrice = $price / $divisor;
                    
                    $baseQuantity = $volumeUnitMult;
                    $productQuantity = $volumeResult;
                    $volumeUnit = 'L';
                    $code = str_replace('^3', '3', $volumeUnit);
                    break;
                default:
                    $count = 1;
                    $countUnit = $metaData[$metaKeys['unitRegularUnitKey']];
                    $countUnitMult = (float)$metaData[$metaKeys['unitRegularMultiplikatorKey']];
                    
                    if ($count === 0 || !$count || is_null($count)) {
                        $count = (float)$countUnitMult;
                    }
                    
                    $divisor = $count / $countUnitMult;
                    $basePrice = $price / $divisor;
                    
                    $baseQuantity = $countUnitMult;
                    $productQuantity = $count;
                    $code = $countUnit;
                    break;
            }
            
            \update_post_meta(
                $wcProduct->get_id(),
                $metaKeys['unitRegularUnitKey'],
                $code,
                $metaData[$metaKeys['unitRegularUnitKey']]
            );
            \update_post_meta(
                $wcProduct->get_id(),
                $metaKeys['unitRegularMultiplikatorKey'],
                $baseQuantity,
                $metaData[$metaKeys['unitRegularMultiplikatorKey']]
            );
            \update_post_meta(
                $wcProduct->get_id(),
                $metaKeys['unitRegularKey'],
                $basePrice,
                $metaData[$metaKeys['unitRegularKey']]
            );
            \update_post_meta(
                $wcProduct->get_id(),
                $metaKeys['unitSaleUnitKey'],
                $code,
                $metaData[$metaKeys['unitSaleUnitKey']]
            );
            \update_post_meta(
                $wcProduct->get_id(),
                $metaKeys['unitSaleMultiplikatorKey'],
                $baseQuantity,
                $metaData[$metaKeys['unitSaleMultiplikatorKey']]
            );
            \update_post_meta(
                $wcProduct->get_id(),
                $metaKeys['unitSalePriceKey'],
                $basePrice,
                $metaData[$metaKeys['unitSalePriceKey']]
            );
            
            $id = new Identity($code);
            
            $product->setMeasurementQuantity((float)$productQuantity);
            $product->setMeasurementUnitId($id);
            $product->setMeasurementUnitCode($code);
            $product->setConsiderBasePrice(true);
            $product->setBasePriceQuantity((float)$baseQuantity);
            $product->setBasePriceUnitId($id);
            $product->setBasePriceUnitCode($code);
            $product->setBasePriceUnitName($code);
        }
    }
    
    private function setRRPProperty(ProductModel &$product, \WC_Product $wcProduct)
    {
        $rrp = get_post_meta($wcProduct->get_id(), 'bm_rrp', true);
        if ($rrp !== '' && !is_null($rrp) && !empty($rrp)) {
            $product->setRecommendedRetailPrice((float)$rrp);
        }
    }
    
    /**
     * @return array
     */
    private function getGermanMarketMetaKeys($isMaster = false)
    {
        $result = [
            //Price
            'priceKey'     => '_price',
            //meta keys vars
            'weightKey'    => '_weight',
            'lengthKey'    => '_length',
            'widthKey'     => '_width',
            'heightKey'    => '_height',
            'jtlwccStkKey' => '_jtlwcc_stk',
        ];
        $keys = [  //meta keys PPU vars
                   'unitRegularUnitKey'          => '_unit_regular_price_per_unit',
                   'unitRegularMultiplikatorKey' => '_unit_regular_price_per_unit_mult',
                   'unitRegularKey'              => '_regular_price_per_unit',
                   'unitSaleUnitKey'             => '_unit_sale_price_per_unit',
                   'unitSaleMultiplikatorKey'    => '_unit_sale_price_per_unit_mult',
                   'unitSalePriceKey'            => '_sale_price_per_unit',
        ];
        
        foreach ($keys as $key => $value) {
            if ($isMaster) {
                $result[$key] = $value;
            } else {
                $result[$key] = '_v' . $value;
            }
        }
        
        return $result;
    }
    
    /**
     * @param \WC_Product $wcProduct
     * @param             $metaKeys
     *
     * @return bool
     */
    private function hasGermanMarketUnitPrice(\WC_Product $wcProduct, $metaKeys)
    {
        $result = false;
        /** @var \WC_Meta_Data $meta */
        foreach ($wcProduct->get_meta_data() as $meta) {
            if ($result) {
                continue;
            }
            if (count($meta->get_data()) > 0 && isset($meta->get_data()['key'])) {
                if ($meta->get_data()['key'] === $metaKeys['unitRegularMultiplikatorKey']) {
                    $value = \get_post_meta($wcProduct->get_id(), $metaKeys['unitRegularMultiplikatorKey'], true);
                    if (isset($value) && !is_null($value) && $value !== false) {
                        $value = (float)$value;
                        $result = $value > 0.00 ? true : false;
                    };
                }
            }
        }
        
        return $result;
    }
    
    /**
     * @param \WC_Product $wcProduct
     * @param             $metaKeys
     *
     * @return array
     */
    private function getGermanMarketMeta(\WC_Product $wcProduct, $metaKeys)
    {
        $result = [];
        
        foreach ($metaKeys as $metaKey => $meta) {
            $result[$meta] = \get_post_meta($wcProduct->get_id(), $meta, true);
        }
        
        return $result;
    }
    
    /**
     * @param $metaIdent
     *
     * @return string
     */
    private function identifyGermanMarketMetaGroup($metaIdent)
    {
        $weight = [
            'mg',
            'g',
            'kg',
            'lbs',
            'lb',
            't',
            'oz',
        ];
        
        $surfaces = [
            'mm2',
            'cm2',
            'dm2',
            'm2',
            'km2',
        ];
        
        $length = [
            'mm',
            'cm',
            'dm',
            'm',
            'dm',
            'km',
            'in',
            'yd',
        ];
        
        $volumes = [
            'mm3',
            'cm3',
            'dm3',
            'm3',
            'dm3',
            'km3',
            'l',
            'ml',
            'cl',
            'dl',
            'L',
            'mL',
            'cL',
            'dL',
        ];
        
        if (array_search($metaIdent, $weight)) {
            return 'weight';
        } elseif (array_search($metaIdent, $surfaces)) {
            return 'surface';
        } elseif (array_search($metaIdent, $length)) {
            return 'length';
        } elseif (array_search($metaIdent, $volumes)) {
            return 'volume';
        } else {
            return 'standard';
        }
    }
    
    /**
     * @param ProductModel $product
     *
     * @throws NonNumericValue
     * @throws NonStringUnitName
     */
    public function pushData(ProductModel $product)
    {
        $this->updateGermanMarketPPU($product);
        $this->updateRRP($product);
    }
    
    /**
     * @param ProductModel $product
     *
     * @throws NonNumericValue
     * @throws NonStringUnitName
     */
    private function updateGermanMarketPPU(ProductModel $product)
    {
        $metaKeys = $this->getGermanMarketMetaKeys($product->getMasterProductId()->getEndpoint() === 0);
        
        if ($product->getConsiderBasePrice()) {
            $productId = $product->getId()->getEndpoint();
            $metaData = $this->getGermanMarketMeta(\wc_get_product($productId), $metaKeys);
            
            $wcWeightOption = \get_option('woocommerce_weight_unit');
            $wcLengthOption = \get_option('woocommerce_dimension_unit');
            $wcSquareOption = $wcLengthOption . '^2';
            $wcVolumeOption = 'L';
            
            $ppuType = $this->identifyGermanMarketMetaGroup($product->getBasePriceUnitCode());
            $code = $product->getBasePriceUnitCode();//g
            $baseQuantity = $product->getBasePriceQuantity(); //1000
            
            $productQuantity = $product->getMeasurementQuantity(); //500
            $productQuantityCode = $product->getMeasurementUnitCode(); //g
            
            $basePrice = null;
            $currenPrice = \get_post_meta($productId, '_price', true);
            $baseUnit = null;
            
            switch ($ppuType) {
                case 'weight':
                    if ($wcWeightOption !== $code) {
                        $mass = new Mass($baseQuantity, $code);
                        $baseQuantity = $mass->toUnit($wcWeightOption);
                    }
                    
                    if ($wcWeightOption !== $productQuantityCode) {
                        $mass = new Mass($productQuantity, $productQuantityCode);
                        $productQuantity = $mass->toUnit($wcWeightOption);
                    }
                    
                    $divisor = $productQuantity / $baseQuantity;
                    
                    $basePrice = $currenPrice / $divisor;
                    $baseUnit = $wcWeightOption;
                    break;
                case 'length':
                    if ($wcLengthOption !== $code) {
                        $length = new Length($baseQuantity, $code);
                        $baseQuantity = $length->toUnit($wcLengthOption);
                    }
                    
                    if ($wcLengthOption !== $productQuantityCode) {
                        $length = new Length($productQuantity, $productQuantityCode);
                        $productQuantity = $length->toUnit($wcLengthOption);
                    }
                    
                    $divisor = $productQuantity / $baseQuantity;
                    
                    $basePrice = $currenPrice / $divisor;
                    $baseUnit = $wcLengthOption;
                    break;
                case 'volume':
                    if (strtolower($wcVolumeOption) !== strtolower($code)) {
                        $vol = new Volume($baseQuantity, strtolower($code));
                        $baseQuantity = $vol->toUnit(strtolower($wcVolumeOption));
                    }
                    
                    if (strtolower($wcVolumeOption) !== strtolower($productQuantityCode)) {
                        $vol = new Volume($productQuantity, strtolower($productQuantityCode));
                        $productQuantity = $vol->toUnit(strtolower($wcVolumeOption));
                    }
                    
                    $divisor = $productQuantity / $baseQuantity;
                    
                    $basePrice = $currenPrice / $divisor;
                    $baseUnit = $wcVolumeOption;
                    break;
                case 'surface':
                    $code = str_replace('2', '^2', $code);
                    $productQuantityCode = str_replace('2', '^2', $productQuantityCode);
                    
                    if (strtolower($wcSquareOption) !== strtolower($code)) {
                        $sur = new Area($baseQuantity, strtolower($code));
                        $baseQuantity = $sur->toUnit(strtolower($wcSquareOption));
                    }
                    
                    if (strtolower($wcSquareOption) !== strtolower($productQuantityCode)) {
                        $sur = new Area($productQuantity, strtolower($productQuantityCode));
                        $productQuantity = $sur->toUnit(strtolower($wcSquareOption));
                    }
                    
                    $divisor = $productQuantity / $baseQuantity;
                    
                    $basePrice = $currenPrice / $divisor;
                    $baseUnit = str_replace('^2', '2', $wcSquareOption);;
                    break;
                default:
                    $this->clearPPU($product, $metaKeys);
                    break;
            }
            
            if (is_null($basePrice) || is_null($baseUnit)) {
                $this->clearPPU($product, $metaKeys);
            } else {
                $unitCodeKey = $metaKeys['unitRegularUnitKey'];
                $unitMultiplikatorKey = $metaKeys['unitRegularMultiplikatorKey'];
                $basePriceKey = $metaKeys['unitRegularKey'];
                
                $unitSaleCode = $metaKeys['unitSaleUnitKey'];
                $unitSaleMultiplikatorKey = $metaKeys['unitSaleMultiplikatorKey'];
                $baseSalePriceKey = $metaKeys['unitSalePriceKey'];
                
                \update_post_meta(
                    $productId,
                    $unitCodeKey,
                    $baseUnit,
                    $metaData[$unitCodeKey]
                );
                \update_post_meta(
                    $productId,
                    $unitMultiplikatorKey,
                    $baseQuantity,
                    $metaData[$unitMultiplikatorKey]
                );
                \update_post_meta(
                    $productId,
                    $basePriceKey,
                    round($basePrice, 4),
                    $metaData[$basePriceKey]
                );
                
                \update_post_meta(
                    $productId,
                    $unitSaleCode,
                    $baseUnit,
                    $metaData[$unitSaleCode]
                );
                \update_post_meta(
                    $productId,
                    $unitSaleMultiplikatorKey,
                    $baseQuantity,
                    $metaData[$unitSaleMultiplikatorKey]
                );
                \update_post_meta(
                    $productId,
                    $baseSalePriceKey,
                    round($basePrice, 4),
                    $metaData[$baseSalePriceKey]
                );
            }
        } else {
            $this->clearPPU($product, $metaKeys);
        }
    }
    
    /**
     * @param ProductModel $product
     * @param              $metaKeys
     */
    private function clearPPU(ProductModel $product, $metaKeys)
    {
        $productId = $product->getId()->getEndpoint();
        $metaData = $this->getGermanMarketMeta(
            \wc_get_product($productId),
            $metaKeys
        );
        \update_post_meta(
            $productId,
            $metaKeys['unitRegularMultiplikatorKey'],
            '',
            $metaData[$metaKeys['unitRegularMultiplikatorKey']]
        );
        \update_post_meta(
            $productId,
            $metaKeys['unitRegularKey'],
            '',
            $metaData[$metaKeys['unitRegularKey']]
        );
        \update_post_meta(
            $productId,
            $metaKeys['unitSaleMultiplikatorKey'],
            '',
            $metaData[$metaKeys['unitSaleMultiplikatorKey']]
        );
        \update_post_meta(
            $productId,
            $metaKeys['unitSalePriceKey'],
            '',
            $metaData[$metaKeys['unitSalePriceKey']]
        );
    }
    
    /**
     * @param ProductModel $product
     */
    private function updateRRP(ProductModel $product)
    {
        $wcProduct = \wc_get_product($product->getId()->getEndpoint());
        $rrp = $product->getRecommendedRetailPrice();
        $oldValue = \get_post_meta($wcProduct->get_id(), 'bm_rrp', true);
        
        if ($rrp !== $oldValue) {
            if (!$product->getMasterProductId()->getEndpoint() === 0) {
                $vKey = sprintf('bm_%s_rrp', $wcProduct->get_id());
                \update_post_meta(
                    $wcProduct->get_parent_id(),
                    $vKey,
                    $rrp,
                    \get_post_meta($wcProduct, $vKey, true)
                );
            }
            \update_post_meta(
                $wcProduct->get_id(),
                'bm_rrp',
                $rrp,
                \get_post_meta($wcProduct->get_id(), 'bm_rrp', true)
            );
        }
    }
}
