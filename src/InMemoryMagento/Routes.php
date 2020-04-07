<?php

declare(strict_types=1);

namespace Webgriffe\AmpMagento\InMemoryMagento;

use Amp\Artax\HttpException;
use Amp\Artax\Request;
use FastRoute\DataGenerator;
use FastRoute\RouteCollector;
use FastRoute\RouteParser;
use Webmozart\Assert\Assert;

class Routes extends RouteCollector
{
    use Utils;

    const ADMIN_USER                        = 'admin';
    const ADMIN_PASS                        = 'password123';
    const DEFAULT_VISIBILITY_CATALOG_SEARCH = 4;

    /**
     * attribute_code (string): Code of the attribute. ,
     * frontend_input (string): HTML for input element. ,
     * entity_type_id (string): Entity type id ,
     * is_required (boolean): Attribute is required. ,
     * frontend_labels (Array[eav-data-attribute-frontend-label-interface]): Frontend label for each store ,
     *
     * @var array
     */
    public static $invoices          = [];
    public static $stockItems        = [];
    public static $productAttributes = [];
    public static $shipments         = [];
    public static $orders            = [];
    public static $categories        = [];
    public static $products          = [];

    protected static $imagesIncrementalNumber = 0;

    public function __construct()
    {
        parent::__construct(new RouteParser\Std(), new DataGenerator\GroupCountBased());

        self::$productAttributes = [];
        self::$categories        = [];
        self::$products          = [];
        self::$invoices          = [];
        self::$orders            = [];
        self::$stockItems        = [];
        self::$shipments         = [];

        $this->addRoute(
            'POST',
            '/rest/all/V1/integration/admin/token',
            [__CLASS__, 'postIntegrationAdminTokenHandler']
        );
        $this->addRoute('GET', '/rest/all/V1/products/attributes', [__CLASS__, 'getProductsAttributesHandler']);
        $this->addRoute('GET', '/rest/all/V1/categories/list', [__CLASS__, 'getCategoriesListHandler']);
        $this->addRoute('GET', '/rest/all/V1/products/{sku}', [__CLASS__, 'getProductHandler']);
        $this->addRoute('GET', '/rest/all/V1/products', [__CLASS__, 'getProductsHandler']);
        $this->addRoute('GET', '/rest/{storeCode}/V1/products', [__CLASS__, 'getProductsForStoreHandler']);
        $this->addRoute('POST', '/rest/all/V1/products', [__CLASS__, 'postProductsHandler']);
        $this->addRoute('PUT', '/rest/all/V1/products/{sku}', [__CLASS__, 'putProductsHandler']);
        $this->addRoute('PUT', '/rest/{storeCode}/V1/products/{sku}', [__CLASS__, 'putProductsForStoreViewHandler']);
        $this->addRoute('GET', '/rest/all/V1/products/{sku}/media', [__CLASS__, 'getProductMediasHandler']);
        $this->addRoute('POST', '/rest/all/V1/products/{sku}/media', [__CLASS__, 'postProductMediaHandler']);
        $this->addRoute('PUT', '/rest/all/V1/products/{sku}/media/{entryid}', [__CLASS__, 'putProductMediaHandler']);
        $this->addRoute(
            'POST',
            '/rest/all/V1/products/attributes/{attributeCode}/options',
            [__CLASS__, 'postProductsAttributesOptionsHandler']
        );
        $this->addRoute(
            'GET',
            '/rest/all/V1/products/attributes/{attributeCode}/options',
            [__CLASS__, 'getProductAttributesOptionsHandler']
        );
        $this->addRoute(
            'GET',
            '/rest/{storeCode}/V1/products/attributes/{attributeCode}/options',
            [__CLASS__, 'getProductAttributesOptionsForStoreViewHandler']
        );
        $this->addRoute(
            'POST',
            '/rest/all/V1/configurable-products/{parentSku}/child',
            [__CLASS__, 'postConfigurableProductsChildHandler']
        );
        $this->addRoute('GET', '/rest/all/V1/invoices', [__CLASS__, 'getInvoicesHandler']);
        $this->addRoute('GET', '/rest/all/V1/shipments', [__CLASS__, 'getShipmentsHandler']);
        $this->addRoute('GET', '/rest/all/V1/orders/{orderId}', [__CLASS__, 'getOrderHandler']);
        $this->addRoute('GET', '/rest/all/V1/orders', [__CLASS__, 'getOrdersHandler']);
        $this->addRoute('GET', '/rest/all/V1/stockItems/{sku}', [__CLASS__, 'getStockItemsHandler']);
        $this->addRoute(
            'PUT',
            '/rest/all/V1/products/{sku}/stockItems/{stockItemId}',
            [__CLASS__, 'putProductsStockItemsHandler']
        );
        $this->addRoute('POST', '/rest/all/V1/order/{orderId}/ship', [__CLASS__, 'postOrderShipHandler']);
        $this->addRoute('POST', '/rest/all/V1/order/{orderId}/invoice', [__CLASS__, 'postOrderInvoiceHandler']);
        $this->addRoute('POST', '/rest/all/V1/orders/{orderId}/cancel', [__CLASS__, 'postOrderCancelHandler']);

        self::$imagesIncrementalNumber = 0;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws \Throwable
     *
     * BEFORE
     * $product =
     * [
     *   'sku' => 'sku-123',
     *   'price'   => 10,
     *   '_stores' => [
     *       'it_it' => [
     *           'price' => 20
     *       ]
     *   ]
     * ]
     *
     * AFTER
     * $product =
     * [
     *   'sku' => 'sku-123',
     *   'price'   => 50,    <- but is referred to it_it storeCode
     *   '_stores' => [
     *       'it_it' => [
     *           'price' => 20
     *       ]
     *   ]
     * ]
     *
     * Api call of magento doesn't have '_stores' value
     */
    public static function putProductsForStoreViewHandler(Request $request, array $uriParams): ResponseStub
    {
        $sku     = $uriParams['sku'];
        $product = self::readDecodedRequestBody($request)->product;

        // Product data updated can be found on "object" root
        // We have to take that data
        unset($product->_stores);
        if (empty(self::$products[$sku]->_stores)) {
            self::$products[$sku]->_stores = new \stdClass();
        }
        self::$products[$sku]->_stores->{$uriParams['storeCode']} = $product;

        $response = new ResponseStub(200, json_encode(self::$products[$sku]));

        return $response;
    }

    public static function getProductMediasHandler(Request $request, array $uriParams): ResponseStub
    {
        $sku = $uriParams['sku'];
        if (!array_key_exists($sku, self::$products)) {
            return new ResponseStub(404, json_encode(['message' => 'Product not found']));
        }

        $product = self::$products[$sku];

        return new ResponseStub(200, json_encode($product->media_gallery_entries));
    }

    public static function postProductMediaHandler(Request $request, array $uriParams): ResponseStub
    {
        $sku = $uriParams['sku'];
        if (!array_key_exists($sku, self::$products)) {
            return new ResponseStub(404, json_encode(['message' => 'Product not found']));
        }

        $newMedia = self::readDecodedRequestBody($request)->entry;

        return self::updateProductMediaGallery($sku, $newMedia);
    }

    public static function putProductMediaHandler(Request $request, array $uriParams): ResponseStub
    {
        $sku = $uriParams['sku'];
        if (!array_key_exists($sku, self::$products)) {
            return new ResponseStub(404, json_encode(['message' => 'Product not found']));
        }

        $newMedia = self::readDecodedRequestBody($request)->entry;

        return self::updateProductMediaGallery($sku, $newMedia, $uriParams['entryid']);
    }

    private static function updateProductMediaGallery($sku, \stdClass $newMedia, $entryId = null)
    {
        if (isset($newMedia->id)) {
            unset($newMedia->id);
        }

        if (isset($newMedia->file)) {
            unset($newMedia->file);
        }

        if (isset($newMedia->content)) {
            //Save these fields so that they can be checked by a test assertion later on
            $newMedia->testData = [
                'content' => base64_decode($newMedia->content->base64_encoded_data),
                'type'    => $newMedia->content->type,
                'name'    => $newMedia->content->name,
            ];
            unset($newMedia->content);
        }

        //Just a random file name
        $newMedia->file = 'fakefile'.(self::$imagesIncrementalNumber++).'.jpg';

        $response = new ResponseStub(200, json_encode(true));
        if (!$entryId) {
            if (count(self::$products[$sku]->media_gallery_entries) > 0) {
                $entryId = max(array_keys(self::$products[$sku]->media_gallery_entries)) + 1;
            } else {
                $entryId = 1;
            }
            $response = new ResponseStub(200, json_encode($entryId));
        } elseif (!array_key_exists($entryId, self::$products[$sku]->media_gallery_entries)) {
            return new ResponseStub(404, json_encode(['message' => 'Media not found']));
        }

        $newMedia->id                                          = $entryId;
        self::$products[$sku]->media_gallery_entries[$entryId] = $newMedia;

        //Watch out for the flags (base image, small image, thumbnail etc.) When one of these flags is set to an image,
        //it must be removed from all others
        foreach (self::$products[$sku]->media_gallery_entries as $mediaId => $media) {
            if ($mediaId == $entryId) {
                continue;
            }

            self::$products[$sku]->media_gallery_entries[$mediaId]->types = array_diff($media->types, $newMedia->types);
        }

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws \Throwable
     */
    public static function postIntegrationAdminTokenHandler(Request $request, array $uriParams): ResponseStub
    {
        $response = new ResponseStub(401, json_encode(['message' => 'Login failed']));
        if (self::readDecodedRequestBody($request)->username === self::ADMIN_USER &&
            self::readDecodedRequestBody($request)->password === self::ADMIN_PASS) {
            $response = new ResponseStub(200, json_encode(uniqid('', true)));
        }

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws HttpException
     */
    public static function getProductsAttributesHandler(Request $request, array $uriParams): ResponseStub
    {
        return self::createSearchCriteriaResponse(
            self::$productAttributes,
            self::buildUriFromString($request->getUri())->getQuery()
        );
    }

    public static function getProductAttributesOptionsForStoreViewHandler(
        Request $request,
        array $uriParams
    ): ResponseStub {
        $attributeCode = $uriParams['attributeCode'];
        $storeCode     = $uriParams['storeCode'];
        $response      = new ResponseStub(404, json_encode(['message' => 'Attribute not found.']));

        if (!empty(self::$productAttributes[$attributeCode]->_stores->{$storeCode})) {
            $response = new ResponseStub(
                200,
                json_encode(self::$productAttributes[$attributeCode]->_stores->{$storeCode}->options)
            );
        }

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws HttpException
     */
    public static function getCategoriesListHandler(Request $request, array $uriParams): ResponseStub
    {
        return self::createSearchCriteriaResponse(
            self::$categories,
            self::buildUriFromString($request->getUri())->getQuery()
        );
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     */
    public static function getProductHandler(Request $request, array $uriParams): ResponseStub
    {
        $sku      = $uriParams['sku'];
        $response = new ResponseStub(404, json_encode(['message' => 'Product not found.']));

        //Sku search seems to be case insensitive in Magento
        foreach (self::$products as $key => $product) {
            if (strcasecmp($key, $sku) === 0) {
                return new ResponseStub(200, json_encode($product));
            }
        }

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     */
    public static function getProductsHandler(Request $request, array $uriParams): ResponseStub
    {
        return self::createSearchCriteriaResponse(
            self::$products,
            self::buildUriFromString($request->getUri())->getQuery()
        );
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     */
    public static function getProductsForStoreHandler(Request $request, array $uriParams): ResponseStub
    {
        return self::createSearchCriteriaResponse(
            self::$products,
            self::buildUriFromString($request->getUri())->getQuery(),
            $uriParams['storeCode']
        );
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws \Throwable
     */
    public static function postProductsHandler(Request $request, array $uriParams): ResponseStub
    {
        $product = self::readDecodedRequestBody($request)->product;
        Assert::isInstanceOf($product, \stdClass::class);
        if (!isset($product->price)) {
            $response = new ResponseStub(
                400,
                json_encode(['message' => 'The value of attribute "price" must be set.'])
            );

            return $response;
        }
        if (isset($product->weight) && !\is_numeric($product->weight)) {
            $response = new ResponseStub(
                400,
                json_encode(['message' => '"Error occurred during "weight" processing. Invalid type.'])
            );

            return $response;
        }
        if (!isset($product->visibility)) {
            $product->visibility = self::DEFAULT_VISIBILITY_CATALOG_SEARCH;
        }
        if (!empty($product->extension_attributes->configurable_product_options)) {
            foreach ($product->extension_attributes->configurable_product_options as $configurableProductOption) {
                if (empty($configurableProductOption->values)) {
                    $response = new ResponseStub(
                        400,
                        json_encode(['message' => 'Option values are not specified.'])
                    );

                    return $response;
                }
            }
        }

        if (isset($product->custom_attributes)) {
            foreach ($product->custom_attributes as $customAttribute) {
                if ($customAttribute->attribute_code == 'url_key') {
                    //Check for duplicated url keys
                    foreach (self::$products as $otherProduct) {
                        if (isset($otherProduct->custom_attributes)) {
                            foreach ($otherProduct->custom_attributes as $otherProductCustomAttribute) {
                                if ($otherProductCustomAttribute->attribute_code == 'url_key') {
                                    if ($otherProductCustomAttribute->value == $customAttribute->value) {
                                        $response = new ResponseStub(
                                            400,
                                            json_encode(['message' => 'URL key for specified store already exists'])
                                        );

                                        return $response;
                                    }

                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        $product->id                   = (string)random_int(1000, 10000);
        self::$products[$product->sku] = $product;
        $response                      = new ResponseStub(200, json_encode($product));

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws \Throwable
     */
    public static function putProductsHandler(Request $request, array $uriParams): ResponseStub
    {
        $sku     = $uriParams['sku'];
        $product = self::readDecodedRequestBody($request)->product;
        if (isset($product->weight) && !\is_numeric($product->weight)) {
            $response = new ResponseStub(
                400,
                json_encode(['message' => '"Error occurred during "weight" processing. Invalid type.'])
            );

            return $response;
        }
        if (!isset($product->visibility)) {
            $product->visibility = self::DEFAULT_VISIBILITY_CATALOG_SEARCH;
        }
        if (!empty($product->extension_attributes->configurable_product_options)) {
            foreach ($product->extension_attributes->configurable_product_options as $configurableProductOption) {
                if (empty($configurableProductOption->values)) {
                    $response = new ResponseStub(
                        400,
                        json_encode(['message' => 'Option values are not specified.'])
                    );

                    return $response;
                }
            }
        }

        if (isset($product->custom_attributes)) {
            foreach ($product->custom_attributes as $customAttribute) {
                if ($customAttribute->attribute_code == 'url_key') {
                    //Check for duplicated url keys
                    foreach (self::$products as $otherProduct) {
                        if ($otherProduct->sku == $sku) {
                            continue;
                        }

                        if (isset($otherProduct->custom_attributes)) {
                            foreach ($otherProduct->custom_attributes as $otherProductCustomAttribute) {
                                if ($otherProductCustomAttribute->attribute_code == 'url_key') {
                                    if ($otherProductCustomAttribute->value == $customAttribute->value) {
                                        $response = new ResponseStub(
                                            400,
                                            json_encode(['message' => 'URL key for specified store already exists'])
                                        );

                                        return $response;
                                    }

                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        self::$products[$sku] = ObjectMerger::merge(self::$products[$sku], $product);
        $response             = new ResponseStub(200, json_encode(self::$products[$sku]));

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws \Throwable
     */
    public static function postProductsAttributesOptionsHandler(
        Request $request,
        array $uriParams,
        string $mageVersion
    ): ResponseStub {
        $attributeCode = $uriParams['attributeCode'];
        $response      = new ResponseStub(404, json_encode(['message' => 'Attribute not found.']));
        if (!empty(self::$productAttributes[$attributeCode])) {
            $option                                             = self::readDecodedRequestBody($request)->option;
            $option->value                                      = (string)random_int(1000, 10000);
            self::$productAttributes[$attributeCode]->options[] = $option;
            $responseBody                                       = true;
            if ($mageVersion === '2.3') {
                $responseBody = sprintf('id_%s', $option->value);
            }
            $response = new ResponseStub(200, json_encode($responseBody));
        }

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     */
    public static function getProductAttributesOptionsHandler(Request $request, array $uriParams): ResponseStub
    {
        $attributeCode = $uriParams['attributeCode'];
        $response      = new ResponseStub(404, json_encode(['message' => 'Attribute not found.']));
        if (!empty(self::$productAttributes[$attributeCode])) {
            $response = new ResponseStub(200, json_encode(self::$productAttributes[$attributeCode]->options));
        }

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws \Throwable
     */
    public static function postConfigurableProductsChildHandler(Request $request, array $uriParams): ResponseStub
    {
        $parentSku = $uriParams['parentSku'];
        if (!array_key_exists($parentSku, self::$products) ||
            !array_key_exists(self::readDecodedRequestBody($request)->childSku, self::$products)) {
            return new ResponseStub(404, json_encode(['message' => 'Requested product doesn\'t exist']));
        }
        $childId                  = self::$products[self::readDecodedRequestBody($request)->childSku]->id;
        $parent                   = self::$products[$parentSku];
        $configurableProductLinks = $parent->extension_attributes->configurable_product_links ?? null;
        if (!empty($configurableProductLinks) && \in_array($childId, $configurableProductLinks, true)) {
            return new ResponseStub(400, json_encode(['message' => 'Il prodotto è già stato associato']));
        }
        $parent->extension_attributes->configurable_product_links[] = $childId;

        return new ResponseStub(200, json_encode(true));
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws HttpException
     */
    public static function getInvoicesHandler(Request $request, array $uriParams): ResponseStub
    {
        return self::createSearchCriteriaResponse(
            self::$invoices,
            self::buildUriFromString($request->getUri())->getQuery()
        );
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     */
    public static function getShipmentsHandler(Request $request, array $uriParams): ResponseStub
    {
        return self::createSearchCriteriaResponse(
            self::$shipments,
            self::buildUriFromString($request->getUri())->getQuery()
        );
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     */
    public static function getOrderHandler(Request $request, array $uriParams): ResponseStub
    {
        $orderId  = $uriParams['orderId'];
        $response = new ResponseStub(404, json_encode(['message' => 'Order not found.']));
        if (isset(self::$orders[$orderId])) {
            $response = new ResponseStub(200, json_encode(self::$orders[$orderId]));
        }

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws HttpException
     */
    public static function getOrdersHandler(Request $request, array $uriParams): ResponseStub
    {
        return self::createSearchCriteriaResponse(
            self::$orders,
            self::buildUriFromString($request->getUri())->getQuery()
        );
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     */
    public static function getStockItemsHandler(Request $request, array $uriParams): ResponseStub
    {
        $sku      = $uriParams['sku'];
        $response = new ResponseStub(404, json_encode(['message' => 'Stock item not found.']));
        if (isset(self::$stockItems[$sku])) {
            $response = new ResponseStub(200, json_encode(self::$stockItems[$sku]));
        }

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws \Throwable
     */
    public static function putProductsStockItemsHandler(Request $request, array $uriParams): ResponseStub
    {
        $sku = $uriParams['sku'];
        if (isset(self::readDecodedRequestBody($request)->stockItem->qty) &&
            !\is_numeric(self::readDecodedRequestBody($request)->stockItem->qty)) {
            $response = new ResponseStub(
                400,
                json_encode(['message' => '"Error occurred during "qty" processing. Invalid type.'])
            );

            return $response;
        }
        $stockItem              = self::readDecodedRequestBody($request)->stockItem;
        self::$stockItems[$sku] = ObjectMerger::merge(self::$stockItems[$sku], $stockItem);
        $response               = new ResponseStub(200, json_encode(self::$stockItems[$sku]->item_id));

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws \Throwable
     */
    public static function postOrderShipHandler(Request $request, array $uriParams): ResponseStub
    {
        $orderId  = $uriParams['orderId'];
        $response = new ResponseStub(404, json_encode(['message' => 'Order with the given ID does not exist.']));

        if (array_key_exists($orderId, self::$orders)) {
            $orderIsFullyShipped = true;
            $decodedRequestBody = self::readDecodedRequestBody($request);
            foreach (self::$orders[$orderId]->items as &$orderItem) {
                $qtyToShip = $orderItem->qty_ordered;
                if (isset($orderItem->qty_canceled)) {
                    $qtyToShip -= $orderItem->qty_canceled;
                }
                if (isset($orderItem->qty_shipped)) {
                    $qtyToShip -= $orderItem->qty_shipped;
                }

                foreach ($decodedRequestBody->items as $requestItem) {
                    if ($requestItem->order_item_id == $orderItem->item_id ||
                        (
                            isset($orderItem->parent_item_id) &&
                            $requestItem->order_item_id == $orderItem->parent_item_id)
                    ) {
                        if ($requestItem->qty < $qtyToShip) {
                            $orderIsFullyShipped = false;
                        }
                        $qtyToShip = $requestItem->qty;
                        break;
                    }
                }

                $qtyShipped = $qtyToShip;
                if (isset($orderItem->qty_shipped)) {
                    $qtyShipped += $orderItem->qty_shipped;
                }

                $orderItem->qty_shipped = $qtyShipped;
            }

            if ($orderIsFullyShipped) {
                foreach (self::$invoices as $invoice) {
                    if ($invoice->order_id == $orderId) {
                        //Crude. Should actually check that the order is fully invoiced and shipped
                        self::$orders[$orderId]->status = 'complete';
                        break;
                    }
                }
            }

            $newShipmentId                      = count(self::$shipments) + 1;
            $newShipment                        = new \stdClass();
            $newShipment->order_id              = $orderId;
            $newShipment->comment               = null;
            $newShipment->tracks                = [];
            if (!empty($decodedRequestBody->comment)) {
                $newShipment->comment = $decodedRequestBody->comment->comment;
            }

            if (!empty($decodedRequestBody->tracks)) {
                $newShipmentTrack = new \stdClass();
                $newShipmentTrack->track_number = $decodedRequestBody->tracks[0]->track_number;
                $newShipment->tracks[] = $newShipmentTrack;
            }

            self::$shipments[$newShipmentId]    = $newShipment;
            $response                           = new ResponseStub(200, json_encode($newShipmentId));
        }

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws \Throwable
     */
    public static function postOrderInvoiceHandler(Request $request, array $uriParams): ResponseStub
    {
        $orderId  = $uriParams['orderId'];
        $response = new ResponseStub(404, json_encode(['message' => 'Order with the given ID does not exist.']));

        if (array_key_exists($orderId, self::$orders)) {
            $orderIsFullyInvoiced = true;
            $decodedRequestBody = self::readDecodedRequestBody($request);
            foreach (self::$orders[$orderId]->items as &$orderItem) {
                $qtyToInvoice = $orderItem->qty_ordered;
                if (isset($orderItem->qty_canceled)) {
                    $qtyToInvoice -= $orderItem->qty_canceled;
                }
                if (isset($orderItem->qty_invoiced)) {
                    $qtyToInvoice -= $orderItem->qty_invoiced;
                }

                foreach ($decodedRequestBody->items as $requestItem) {
                    if ($requestItem->order_item_id == $orderItem->item_id ||
                        (
                            isset($orderItem->parent_item_id) &&
                            $requestItem->order_item_id == $orderItem->parent_item_id)
                    ) {
                        if ($requestItem->qty < $qtyToInvoice) {
                            $orderIsFullyInvoiced = false;
                        }
                        $qtyToInvoice = $requestItem->qty;
                        break;
                    }
                }

                $qtyInvoiced = $qtyToInvoice;
                if (isset($orderItem->qty_invoiced)) {
                    $qtyInvoiced += $orderItem->qty_invoiced;
                }

                $orderItem->qty_invoiced = $qtyInvoiced;
            }

            if ($orderIsFullyInvoiced) {
                foreach (self::$shipments as $shipment) {
                    if ($shipment->order_id == $orderId) {
                        //Crude. Should actually check that the order is fully invoiced and shipped
                        self::$orders[$orderId]->status = 'complete';
                        break;
                    }
                }
            }

            $invoiceId                  = count(self::$invoices) + 1;
            $newInvoice                 = new \stdClass();
            $newInvoice->order_id       = $orderId;
            self::$invoices[$invoiceId] = $newInvoice;
            $response                   = new ResponseStub(200, json_encode($invoiceId));
        }

        return $response;
    }

    /**
     * @param Request $request
     * @param array   $uriParams
     *
     * @return ResponseStub
     * @throws \Throwable
     */
    public static function postOrderCancelHandler(Request $request, array $uriParams): ResponseStub
    {
        $orderId  = $uriParams['orderId'];
        $response = new ResponseStub(404, json_encode(['message' => 'Order with the given ID does not exist.']));

        if (array_key_exists($orderId, self::$orders)) {
            $status = self::$orders[$orderId]->status;
            if (!in_array($status, ['complete', 'closed', 'canceled'])) {
                $status = 'canceled';
                foreach (self::$shipments as $shipment) {
                    if ($shipment->order_id == $orderId) {
                        $status = 'complete';
                    }
                }

                foreach (self::$invoices as $invoice) {
                    if ($invoice->order_id == $orderId) {
                        $status = 'complete';
                    }
                }

                self::$orders[$orderId]->status = $status;

                if (self::$orders[$orderId]->items) {
                    /** @var \stdClass $item */
                    foreach (self::$orders[$orderId]->items as &$item) {
                        if (isset($item->qty_ordered)) {
                            $qtyOrdered = $item->qty_ordered;
                            $qtyActuallyUsed = 0;
                            if (isset($item->qty_shipped)) {
                                $qtyActuallyUsed = max($qtyActuallyUsed, $item->qty_shipped);
                            }
                            if (isset($item->qty_invoiced)) {
                                $qtyActuallyUsed = max($qtyActuallyUsed, $item->qty_invoiced);
                            }
                            $item->qty_canceled = $qtyOrdered - $qtyActuallyUsed;
                        }
                    }
                }
            }

            $response = new ResponseStub(200, json_encode(true));
        }

        return $response;
    }
}
