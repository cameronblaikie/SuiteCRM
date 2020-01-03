<?php
namespace Api\V8\Service;

use Api\V8\BeanDecorator\BeanManager;
use Api\V8\JsonApi\Helper\AttributeObjectHelper;
use Api\V8\JsonApi\Helper\PaginationObjectHelper;
use Api\V8\JsonApi\Helper\RelationshipObjectHelper;
use Api\V8\JsonApi\Response\DataResponse;
use Api\V8\JsonApi\Response\DocumentResponse;
use Api\V8\JsonApi\Response\MetaResponse;
use Api\V8\Param\CreateModuleParams;
use Api\V8\Param\DeleteModuleParams;
use Api\V8\Param\GetModuleParams;
use Api\V8\Param\GetModulesParams;
use Api\V8\Param\UpdateModuleParams;
use Slim\Http\Request;
use SuiteCRM\Exception\AccessDeniedException;

class ModuleService
{
    /**
     * @var BeanManager
     */
    protected $beanManager;

    /**
     * @var AttributeObjectHelper
     */
    protected $attributeHelper;

    /**
     * @var RelationshipObjectHelper
     */
    protected $relationshipHelper;

    /**
     * @var PaginationObjectHelper
     */
    protected $paginationHelper;

    /**
     * @param BeanManager $beanManager
     * @param AttributeObjectHelper $attributeHelper
     * @param RelationshipObjectHelper $relationshipHelper
     * @param PaginationObjectHelper $paginationHelper
     */
    public function __construct(
        BeanManager $beanManager,
        AttributeObjectHelper $attributeHelper,
        RelationshipObjectHelper $relationshipHelper,
        PaginationObjectHelper $paginationHelper
    ) {
        $this->beanManager = $beanManager;
        $this->attributeHelper = $attributeHelper;
        $this->relationshipHelper = $relationshipHelper;
        $this->paginationHelper = $paginationHelper;
    }

    /**
     * @param GetModuleParams $params
     * @param $path
     * @return DocumentResponse
     * @throws AccessDeniedException
     */
    public function getRecord(GetModuleParams $params, $path)
    {
        $fields = $params->getFields();
        $bean = $this->beanManager->getBeanSafe(
            $params->getModuleName(),
            $params->getId()
        );

        if (!$bean->ACLAccess('view')) {
            throw new AccessDeniedException();
        }

        $dataResponse = $this->getDataResponse($bean, $fields, $path);

        $response = new DocumentResponse();
        $response->setData($dataResponse);

        return $response;
    }

    /**
     * @param GetModulesParams $params
     * @param Request $request
     * @return DocumentResponse
     * @throws AccessDeniedException
     */
    public function getRecords(GetModulesParams $params, Request $request)
    {
        global $db;
        // this whole method should split into separated classes later
        $module = $params->getModuleName();
        $orderBy = $params->getSort();
        $where = $params->getFilter();
        $fields = $params->getFields();

        $size = $params->getPage()->getSize();
        $number = $params->getPage()->getNumber();

        $bean = $this->beanManager->newBeanSafe(
            $params->getModuleName()
        );

        if (!$bean->ACLAccess('view')) {
            throw new AccessDeniedException();
        }

        // negative numbers are validated in params
        $offset = $number !== 0 ? ($number - 1) * $size : $number;
        $realRowCount = $this->beanManager->countRecords($module, $where);
        $limit = $size === BeanManager::DEFAULT_ALL_RECORDS ? BeanManager::DEFAULT_LIMIT : $size;
        $deleted = $params->getDeleted();
        $data = [];

        if (empty($fields)) {
            $fields = $this->beanManager->getDefaultFields($bean);
        }


        // Detect if bean has email field
        if ((property_exists($bean, 'email1') && strpos($where, 'email1')) || (property_exists($bean,
                    'email2') && strpos($where, 'email2'))) {



            $selectedFields = substr( strtolower($module), 0, 1).'.'. implode(','. substr( strtolower($module), 0, 1).'.' , $fields);

            $quotedFields = $db->quote {
                $selectedFields
            };

            $selectedModule = strtolower($module).' ' . substr( strtolower($module), 0, 1);

            $quotedModule = $db->quote {
                $selectedModule
            };

            $selectedModuleName = "'" . $module . "'";

            $quotedModuleName = $db->quote {
              $selectedModuleName
            };

            $selectEmailAddress = "'" . $_REQUEST['filter'] ['email1'] ['eq'] . "'";

            $query = "SELECT {$selectedFields} FROM email_addresses ea join email_addr_bean_rel eabr on ea.id = eabr.email_address_id join {$selectedModule} on a.id = eabr.bean_id ";
            $query .= " where email_address= '" . $_REQUEST['filter']['email1']['eq'] . "'";
            $query .= " and eabr.bean_module= '" . $module . "'";


           $result = $db->query($query, true, "");

//           $data = $db->fetchByAssoc($result);

           while (($row = $db->fetchByAssoc($result))) {
               $data[] = $row;
           }


            } else {
           $beanListResponse = $this->beanManager->getList($module)
               ->orderBy($orderBy)
               ->where($where)
               ->offset($offset)
               ->limit($limit)
               ->max($size)
               ->deleted($deleted)
               ->fields($this->beanManager->filterAcceptanceFields($bean, $fields))
               ->fetch();


           $beanArray = [];
           foreach ($beanListResponse->getBeans() as $bean) {
               $bean = $this->beanManager->getBeanSafe(
                   $params->getModuleName(),
                   $bean->id
               );
               $beanArray[] = $bean;
           }
           $data = [];
           foreach ($beanArray as $bean) {
               $dataResponse = $this->getDataResponse(
                   $bean,
                   $fields,
                   $request->getUri()->getPath() . '/' . $bean->id
               );

               $data[] = $dataResponse;
           }
       }

            $response = new DocumentResponse();
            $response->setData($data);

            // pagination
            if ($data && $limit !== BeanManager::DEFAULT_LIMIT) {
                $totalPages = ceil($realRowCount / $size);

                $paginationMeta = $this->paginationHelper->getPaginationMeta($totalPages, count($data));
                $paginationLinks = $this->paginationHelper->getPaginationLinks($request, $totalPages, $number);

                $response->setMeta($paginationMeta);
                $response->setLinks($paginationLinks);
            }

            return $response;
        }

    /**
     * @param CreateModuleParams $params
     * @param Request $request
     *
     * @return DocumentResponse
     * @throws \InvalidArgumentException When bean is already exist.
     * @throws AccessDeniedException
     */
    public function createRecord(CreateModuleParams $params, Request $request)
    {
        $module = $params->getData()->getType();
        $id = $params->getData()->getId();
        $attributes = $params->getData()->getAttributes();

        if ($id !== null && $this->beanManager->getBean($module, $id, [], false) instanceof \SugarBean) {
            throw new \InvalidArgumentException(sprintf(
                'Bean %s with id %s is already exist',
                $module,
                $id
            ));
        }

        $bean = $this->beanManager->newBeanSafe($module);

        if (!$bean->ACLAccess('save')) {
            throw new AccessDeniedException();
        }

        if ($id !== null) {
            $bean->id = $id;
            $bean->new_with_id = true;
        }

        $this->setRecordUpdateParams($bean, $attributes);

        foreach ($attributes as $property => $value) {
            $bean->$property = $value;
        }

        $bean->save();
        
        $bean->retrieve($bean->id);

        $dataResponse = $this->getDataResponse(
            $bean,
            null,
            $request->getUri()->getPath() . '/' . $bean->id
        );

        $response = new DocumentResponse();
        $response->setData($dataResponse);

        return $response;
    }

    /**
     * @param UpdateModuleParams $params
     * @param Request $request
     * @return DocumentResponse
     * @throws AccessDeniedException
     */
    public function updateRecord(UpdateModuleParams $params, Request $request)
    {
        $module = $params->getData()->getType();
        $id = $params->getData()->getId();
        $attributes = $params->getData()->getAttributes();
        $bean = $this->beanManager->getBeanSafe($module, $id);

        if (!$bean->ACLAccess('save')) {
            throw new AccessDeniedException();
        }

        $this->setRecordUpdateParams($bean, $attributes);

        foreach ($attributes as $property => $value) {
            $bean->$property = $value;
        }

        $bean->save();
        
        $bean->retrieve($bean->id);


        $dataResponse = $this->getDataResponse(
            $bean,
            null,
            $request->getUri()->getPath() . '/' . $bean->id
        );

        $response = new DocumentResponse();
        $response->setData($dataResponse);

        return $response;
    }

    /**
     * @param \SugarBean $bean
     * @param array $attributes
     */
    protected function setRecordUpdateParams(\SugarBean $bean, array $attributes)
    {
        $bean->set_created_by = !(isset($attributes['created_by']) || isset($attributes['created_by_name']));
        $bean->update_modified_by = !(isset($attributes['modified_user_id']) || isset($attributes['modified_by_name']));
        $bean->update_date_entered = isset($attributes['date_entered']);
        $bean->update_date_modified = !isset($attributes['date_modified']);
    }

    /**
     * @param DeleteModuleParams $params
     * @return DocumentResponse
     * @throws AccessDeniedException
     */
    public function deleteRecord(DeleteModuleParams $params)
    {
        $bean = $this->beanManager->getBeanSafe(
            $params->getModuleName(),
            $params->getId()
        );

        if (!$bean->ACLAccess('delete')) {
            throw new AccessDeniedException();
        }

        $bean->mark_deleted($bean->id);

        $response = new DocumentResponse();
        $response->setMeta(
            new MetaResponse(['message' => sprintf('Record with id %s is deleted', $bean->id)])
        );

        return $response;
    }

    /**
     * @param \SugarBean $bean
     * @param array|null $fields
     * @param string|null $path
     *
     * @return DataResponse
     */
    public function getDataResponse(\SugarBean $bean, $fields = null, $path = null)
    {
        // this will be split into separated classed later
        $dataResponse = new DataResponse($bean->getObjectName(), $bean->id);
        $dataResponse->setAttributes($this->attributeHelper->getAttributes($bean, $fields));
        $dataResponse->setRelationships($this->relationshipHelper->getRelationships($bean, $path));

        return $dataResponse;
    }
}
