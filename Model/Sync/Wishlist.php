<?php

namespace Dotdigitalgroup\Email\Model\Sync;

use Dotdigitalgroup\Email\Model\Importer;

class Wishlist
{
    /**
     * @var \Dotdigitalgroup\Email\Helper\Data
     */
    protected $_helper;
    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resource;
    /**
     * @var
     */
    protected $_objectManager;
    /**
     * @var
     */
    protected $_wishlists;
    /**
     * @var array
     */
    protected $_wishlistIds = [];
    /**
     * @var
     */
    protected $_start;
    /**
     * @var int
     */
    protected $_count = 0;
    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    protected $_customerFactory;
    /**
     * @var \Dotdigitalgroup\Email\Model\ImporterFactory
     */
    protected $_importerFactory;
    /**
     * @var \Magento\Wishlist\Model\WishlistFactory
     */
    protected $_wishlist;
    /**
     * @var \Dotdigitalgroup\Email\Model\Customer\WishlistFactory
     */
    protected $_wishlistFactory;
    /**
     * @var \Dotdigitalgroup\Email\Model\Customer\Wishlist\ItemFactory
     */
    protected $_itemFactory;
    /**
     * @var \Dotdigitalgroup\Email\Model\ResourceModel\Wishlist\CollectionFactory
     */
    protected $_wishlistCollection;
    /**
     * @var \Magento\Wishlist\Model\ResourceModel\Item\CollectionFactory
     */
    protected $_itemCollection;

    /**
     * Wishlist constructor.
     *
     * @param \Magento\Wishlist\Model\ResourceModel\Item\CollectionFactory          $itemCollection
     * @param \Dotdigitalgroup\Email\Model\ResourceModel\Wishlist\CollectionFactory $wishlistCollection
     * @param \Dotdigitalgroup\Email\Model\Customer\Wishlist\ItemFactory            $itemFactory
     * @param \Dotdigitalgroup\Email\Model\Customer\WishlistFactory                 $wishlistFactory
     * @param \Magento\Wishlist\Model\WishlistFactory                               $wishlist
     * @param \Dotdigitalgroup\Email\Model\ImporterFactory                          $importerFactory
     * @param \Magento\Customer\Model\CustomerFactory                               $customerFactory
     * @param \Dotdigitalgroup\Email\Helper\Data                                    $helper
     * @param \Magento\Framework\App\ResourceConnection                             $resource
     * @param \Magento\Framework\Stdlib\DateTime\DateTime                           $datetime
     */
    public function __construct(
        \Magento\Wishlist\Model\ResourceModel\Item\CollectionFactory $itemCollection,
        \Dotdigitalgroup\Email\Model\ResourceModel\Wishlist\CollectionFactory $wishlistCollection,
        \Dotdigitalgroup\Email\Model\Customer\Wishlist\ItemFactory $itemFactory,
        \Dotdigitalgroup\Email\Model\Customer\WishlistFactory $wishlistFactory,
        \Magento\Wishlist\Model\WishlistFactory $wishlist,
        \Dotdigitalgroup\Email\Model\ImporterFactory $importerFactory,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Dotdigitalgroup\Email\Helper\Data $helper,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Framework\Stdlib\DateTime\DateTime $datetime
    ) {
        $this->_itemCollection = $itemCollection;
        $this->_wishlistCollection = $wishlistCollection;
        $this->_itemFactory = $itemFactory;
        $this->_wishlistFactory = $wishlistFactory;
        $this->_wishlist = $wishlist;
        $this->_importerFactory = $importerFactory;
        $this->_customerFactory = $customerFactory;
        $this->_helper = $helper;
        $this->_resource = $resource;
        $this->_datetime = $datetime;
    }

    /**
     * Sync Wishlists.
     *
     * @return array
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function sync()
    {
        $response = ['success' => true, 'message' => 'Done.'];
        $websites = $this->_helper->getWebsites(true);
        foreach ($websites as $website) {
            $wishlistEnabled = $this->_helper->getWebsiteConfig(
                \Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_SYNC_WISHLIST_ENABLED,
                $website
            );
            $apiEnabled = $this->_helper->isEnabled($website);
            $storeIds = $website->getStoreIds();

            if ($wishlistEnabled && $apiEnabled && !empty($storeIds)) {
                //using bulk api
                $this->_start = microtime(true);
                $this->_exportWishlistForWebsite($website);
                //send wishlist as transactional data
                if (isset($this->_wishlists[$website->getId()])) {
                    $this->_helper->log(
                        '---------- Start wishlist bulk sync ----------'
                    );
                    $websiteWishlists = $this->_wishlists[$website->getId()];
                    //register in queue with importer
                    $this->_importerFactory->create()
                        ->registerQueue(
                            \Dotdigitalgroup\Email\Model\Importer::IMPORT_TYPE_WISHLIST,
                            $websiteWishlists,
                            \Dotdigitalgroup\Email\Model\Importer::MODE_BULK,
                            $website->getId()
                        );
                    //mark connector wishlist as  imported
                    $this->_setImported($this->_wishlistIds);
                }
                if (! empty($this->_wishlists)) {
                    $message = 'Total time for wishlist bulk sync : ' . gmdate(
                            'H:i:s', microtime(true) - $this->_start
                        );
                    $this->_helper->log($message);
                }

                //using single api
                $this->_exportWishlistForWebsiteInSingle($website);
            }
        }
        $response['message'] = 'wishlists updated: ' . $this->_count;

        return $response;
    }

    /**
     * Export withlist for website.
     *
     * @param \Magento\Store\Model\Website $website
     */
    protected function _exportWishlistForWebsite(\Magento\Store\Model\Website $website)
    {
        //reset wishlists
        $this->_wishlists = [];
        $this->_wishlistIds = [];
        //sync limit
        $limit = $this->_helper->getWebsiteConfig(
            \Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_TRANSACTIONAL_DATA_SYNC_LIMIT,
            $website
        );
        //wishlist collection
        $emailWishlist = $this->_getWishlistToImport($website, $limit);

        $this->_wishlistIds = $emailWishlist->getColumnValues('wishlist_id');

        if ( ! empty($this->_wishlistIds)) {

            $collection = $this->_wishlist->getCollection()
                ->addFieldToFilter('main_table.wishlist_id', ['in' => $this->_wishlistIds])
                ->addFieldToFilter('customer_id', ['notnull' => 'true']);

            $collection->getSelect()
                ->joinLeft(
                    ['c' => $this->_resource->getTableName('customer/entity')],
                    'c.entity_id = customer_id',
                    ['email', 'store_id']
                );

            foreach ($collection as $wishlist) {

                $connectorWishlist = $this->_wishlistFactory->create();
                $connectorWishlist->setId($wishlist->getId())
                    ->setUpdatedAt($wishlist->getUpdatedAt())
                    ->setCustomerId($wishlist->getCustomerId())
                    ->setEmail($wishlist->getEmail());

                $wishListItemCollection = $wishlist->getItemCollection();
                if ($wishListItemCollection->getSize()) {
                    foreach ($wishListItemCollection as $item) {

                        $product      = $item->getProduct();
                        $wishlistItem = $this->_itemFactory->create();
                        $wishlistItem->setQty($item->getQty())
                            ->setPrice($product);
                        //store for wishlists
                        $connectorWishlist->setItem($wishlistItem);
                        ++$this->_count;
                    }
                    //set wishlists for later use
                    $this->_wishlists[$website->getId()][] = $connectorWishlist;
                }
            }
        }
    }

    /**
     * Get wishlists to import.
     *
     * @param \Magento\Store\Model\Website $website
     * @param int $limit
     *
     * @return mixed
     */
    protected function _getWishlistToImport(\Magento\Store\Model\Website $website, $limit = 100)
    {
        $collection = $this->_wishlistCollection->create()
            ->addFieldToFilter('wishlist_imported', ['null' => true])
            ->addFieldToFilter(
                'store_id', ['in' => $website->getStoreIds()]
            )
            ->addFieldToFilter('item_count', ['gt' => 0]);
        $collection->getSelect()->limit($limit);

        return $collection;
    }

    /**
     * Export single wishilist for website.
     *
     * @param \Magento\Store\Model\Website $website
     */
    protected function _exportWishlistForWebsiteInSingle(\Magento\Store\Model\Website $website)
    {
        //transactional data limit
        $limit = $this->_helper->getWebsiteConfig(
            \Dotdigitalgroup\Email\Helper\Config::XML_PATH_CONNECTOR_TRANSACTIONAL_DATA_SYNC_LIMIT,
            $website
        );
        $collection = $this->_getModifiedWishlistToImport(
            $website, $limit
        );
        $this->_wishlistIds = [];
        //email_wishlist wishlist ids
        $wishlistIds = $collection->getColumnValues('wishlist_id');

        $wishlistCollection = $this->_wishlist->create()
            ->getCollection()
            ->addFieldToFilter('wishlist_id', ['in' => $wishlistIds]);
        $wishlistCollection->getSelect()
            ->joinLeft(
                ['c' => $this->_resource->getTableName('customer/entity')],
                'c.entity_id = customer_id',
                ['email', 'store_id']
            );

        foreach ($wishlistCollection as $wishlist) {

            $wishlistId = $wishlist->getid();
            $wishlistItems = $wishlist->getItemCollection();

            $connectorWishlist = $this->_wishlistFactory->create();
            $connectorWishlist->setId($wishlistId)
                ->setUpdatedAt($wishlist->getUpdatedAt())
                ->setCustomerId($wishlist->getCustomerId())
                ->setEmail($wishlist->getEmail());

            if ($wishlistItems->getSize()) {
                foreach ($wishlistItems as $item) {

                    $product      = $item->getProduct();
                    $wishlistItem = $this->_itemFactory->create()
                        ->setProduct($product)
                        ->setQty($item->getQty())
                        ->setPrice($product);
                    //store for wishlists
                    $connectorWishlist->setItem($wishlistItem);
                    $this->_count++;
                }
                //send wishlist as transactional data
                $this->_start = microtime(true);
                //register in queue with importer
                $check = $this->_importerFactory->create()
                    ->registerQueue( Importer::IMPORT_TYPE_WISHLIST,
                        $connectorWishlist,
                        Importer::MODE_SINGLE,
                        $website->getId()
                    );
                if ($check) {
                    $this->_wishlistIds[] = $wishlistId;
                }
            } else {
                //register in queue with importer
                $check = $this->_importerFactory->create()
                    ->registerQueue( Importer::IMPORT_TYPE_WISHLIST,
                        [$wishlist->getId()],
                        Importer::MODE_SINGLE,
                        $website->getId()
                    );
                if ($check) {
                    $this->_wishlistIds[] = $wishlistId;
                }
            }
        }
        if (!empty($this->_wishlistIds)) {
            $this->_setImported($this->_wishlistIds, true);
        }
    }

    /**
     * Get wishlists marked as modified for website.
     *
     * @param \Magento\Store\Model\Website $website
     * @param int $limit
     *
     * @return mixed
     */
    protected function _getModifiedWishlistToImport(\Magento\Store\Model\Website $website, $limit = 100)
    {
        $collection = $this->_wishlistCollection->create()
            ->addFieldToFilter('wishlist_modified', 1)
            ->addFieldToFilter(
                'store_id', ['in' => $website->getStoreIds()]
            );
        $collection->getSelect()->limit($limit);

        return $collection;
    }

    /**
     * @param      $ids
     * @param bool $modified
     */
    protected function _setImported($ids, $modified = false)
    {
        try {
            $coreResource = $this->_resource;
            $write = $coreResource->getConnection('core_write');
            $tableName = $coreResource->getTableName('email_wishlist');
            $ids = implode(', ', $ids);
            $now = $this->_datetime->gmtDate();

            //mark imported modified wishlists
            if ($modified) {
                $write->update(
                    $tableName,
                    [
                        'wishlist_modified' => new \Zend_Db_Expr('null'),
                        'updated_at' => $now,
                    ],
                    "wishlist_id IN ($ids)"
                );
            } else {
                $write->update(
                    $tableName,
                    ['wishlist_imported' => 1, 'updated_at' => $now],
                    "wishlist_id IN ($ids)"
                );
            }
        } catch (\Exception $e) {
            $this->_helper->debug((string)$e, []);
        }
    }
}
