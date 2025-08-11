<?php

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

$EM_CONF['canto_fal'] = [
    'title' => 'Canto FAL',
    'description' => 'Adds Canto FAL driver.',
    'category' => 'misc',
    'version' => '1.1.2',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'filemetadata' => '13.4.0-13.4.99',
        ],
    ],
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'author' => 'Nicole Hummel',
    'author_email' => 'nicole-typo3@nimut.dev',
    'author_company' => 'biz-design',
];
