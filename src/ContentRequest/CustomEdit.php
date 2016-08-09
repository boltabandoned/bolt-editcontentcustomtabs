<?php

namespace Bolt\Extension\SahAssar\Editcontentcustomtabs\ContentRequest;

use Bolt\Config;
use Bolt\Filesystem\Exception\IOException;
use Bolt\Filesystem\Manager;
use Bolt\Logger\FlashLoggerInterface;
use Bolt\Storage\Entity\Content;
use Bolt\Storage\Entity\TemplateFields;
use Bolt\Storage\EntityManager;
use Bolt\Storage\Repository;
use Bolt\Translation\Translator as Trans;
use Bolt\Users;
use Cocur\Slugify\Slugify;
use Psr\Log\LoggerInterface;
use Bolt\Storage\ContentRequest\Edit;

/**
 * CustomEdit class.
 * Creates the groups for editcontent and makes sure that keys don't clash between
 * fields, templatefields, relations and taxonomies.
 *
 * @author Svante Richter <svante.richter@gmail.com>
 */
class CustomEdit extends Edit
{

    /**
     * Do the edit form for a record.
     *
     * @param Content $content     A content record
     * @param array   $contentType The contenttype data
     * @param boolean $duplicate   If TRUE create a duplicate record
     *
     * @return array
     */
    public function action(Content $content, array $contentType, $duplicate)
    {
        $contentTypeSlug = $contentType['slug'];
        $new = $content->getId() === null ?: false;
        $oldStatus = $content->getStatus();
        $allStatuses = ['published', 'held', 'draft', 'timed'];
        $allowedStatuses = [];

        foreach ($allStatuses as $status) {
            if ($this->users->isContentStatusTransitionAllowed($oldStatus, $status, $contentTypeSlug, $content->getId())) {
                $allowedStatuses[] = $status;
            }
        }

        // For duplicating a record, clear base field values.
        if ($duplicate) {
            $content->setId('');
            $content->setSlug('');
            $content->setDatecreated('');
            $content->setDatepublish('');
            $content->setDatedepublish(null);
            $content->setDatechanged('');
            $content->setUsername('');
            $content->setOwnerid('');

            $this->loggerFlash->info(Trans::__('contenttypes.generic.duplicated-finalize', ['%contenttype%' => $contentTypeSlug]));
        }

        // Set the users and the current owner of this content.
        if ($new || $duplicate) {
            // For brand-new and duplicated items, the creator becomes the owner.
            $contentowner = $this->users->getCurrentUser();
        } else {
            // For existing items, we'll just keep the current owner.
            $contentowner = $this->users->getUser($content->getOwnerid());
        }

        // Build list of incoming non inverted related records.
        $incomingNotInverted = [];
        foreach ($content->getRelation()->incoming($content) as $relation) {
            if ($relation->isInverted()) {
                continue;
            }
            $fromContentType = $relation->getFromContenttype();
            $record = $this->em->getContent($fromContentType . '/' . $relation->getFromId());

            if ($record) {
                $incomingNotInverted[$fromContentType][] = $record;
            }
        }

        // Test write access for uploadable fields.
        $contentType['fields'] = $this->setCanUpload($contentType['fields']);
        $templateFields = $content->getTemplatefields();
        if ($templateFields instanceof TemplateFields && $templateFieldsData = $templateFields->getContenttype()->getFields()) {
            $templateFields->getContenttype()['fields'] = $this->setCanUpload($templateFields->getContenttype()->getFields());
        }

        // Build context for Twig.
        $contextCan = [
            'upload'             => $this->users->isAllowed('files:uploads'),
            'publish'            => $this->users->isAllowed('contenttype:' . $contentTypeSlug . ':publish:' . $content->getId()),
            'depublish'          => $this->users->isAllowed('contenttype:' . $contentTypeSlug . ':depublish:' . $content->getId()),
            'change_ownership'   => $this->users->isAllowed('contenttype:' . $contentTypeSlug . ':change-ownership:' . $content->getId()),
        ];
        $contextHas = [
            'incoming_relations' => count($incomingNotInverted) > 0,
            'relations'          => isset($contentType['relations']),
            'tabs'               => $contentType['groups'] !== false,
            'taxonomy'           => isset($contentType['taxonomy']),
            'templatefields'     => empty($templateFieldsData) ? false : true,
        ];
        $contextValues = [
            'datepublish'        => $this->getPublishingDate($content->getDatepublish(), true),
            'datedepublish'      => $this->getPublishingDate($content->getDatedepublish()),
        ];
        $context = [
            'incoming_not_inv'   => $incomingNotInverted,
            'contenttype'        => $contentType,
            'content'            => $content,
            'allowed_status'     => $allowedStatuses,
            'contentowner'       => $contentowner,
            'fields'             => $this->config->fields->fields(),
            'fieldtemplates'     => $this->getTemplateFieldTemplates($contentType, $content),
            'fieldtypes'         => $this->getUsedFieldtypes($contentType, $content, $contextHas),
            'groups'             => $this->createGroupTabs($contentType, $contextHas),
            'can'                => $contextCan,
            'has'                => $contextHas,
            'values'             => $contextValues,
            'relations_list'     => $this->getRelationsList($contentType),
        ];

        return $context;
    }

    /**
     * Generate tab groups.
     *
     * @param array $contentType
     * @param array $has
     *
     * @return array
     */
    private function createGroupTabs(array $contentType, array $has)
    {
        $groups = [];
        $groupIds = [];

        $addGroup = function ($group, $label) use (&$groups, &$groupIds) {
            $nr = count($groups) + 1;
            $id = rtrim('tab-' . Slugify::create()->slugify($group), '-');
            if (isset($groupIds[$id]) || $id === 'tab') {
                $id .= '-' . $nr;
            }
            $groups[$group] = [
                'label'     => $label,
                'id'        => $id,
                'is_active' => $nr === 1,
                'fields'    => [],
            ];
            $groupIds[$id] = 1;
        };

        foreach ($contentType['groups'] ? $contentType['groups'] : ['ungrouped'] as $group) {
            if ($group === 'ungrouped') {
                $addGroup($group, Trans::__('contenttypes.generic.group.ungrouped'));
            } elseif ($group !== 'meta' && $group !== 'relations' && $group !== 'taxonomy') {
                $default = ['DEFAULT' => ucfirst($group)];
                $key = ['contenttypes', $contentType['slug'], 'group', $group];
                $addGroup($group, Trans::__($key, $default));
            }
        }

        if ($has['relations'] || $has['incoming_relations']) {
            $addGroup('relations', Trans::__('contenttypes.generic.group.relations'));
            $groups['relations']['fields'][] = '*relations';
        }

        if ($has['taxonomy'] || (is_array($contentType['groups']) && in_array('taxonomy', $contentType['groups']))) {
            $addGroup('taxonomy', Trans::__('contenttypes.generic.group.taxonomy'));
            $groups['taxonomy']['fields'][] = '*taxonomy';
        }

        if ($has['templatefields'] || (is_array($contentType['groups']) && in_array('template', $contentType['groups']))) {
            $addGroup('template', Trans::__('contenttypes.generic.group.template'));
            $groups['template']['fields'][] = '*template';
        }

        $addGroup('meta', Trans::__('contenttypes.generic.group.meta'));
        $groups['meta']['fields'][] = '*meta';

        // References fields in tab group data.
        foreach ($contentType['fields'] as $fieldName => $field) {
            $groups[$field['group']]['fields'][] = $fieldName;
        }

        return $groups;
    }
}