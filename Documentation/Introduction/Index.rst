.. ==================================================
.. FOR YOUR INFORMATION
.. --------------------------------------------------
.. -*- coding: utf-8 -*- with BOM.

.. include:: ../_IncludedDirectives.rst

.. _introduction:

============
Introduction
============

Releases
--------

* Currently the source code is available at `Github <https://github.com/froemken/repair_translation>`_
* I have tagged all versions at Github and added a composer.json,
  so you can install different versions of repair_translation with composer.

Bugs and Known Issues
---------------------

If you found a bug, it would be cool if you notify me
about that via the `Bug Tracker <https://github.com/froemken/repair_translation/issues>`_ of Github.

The TYPO3 bug
-------------

Your extbase extension comes with a domain model car f.e.
Go into list module and create a new record of type car. Assign an image to your newly created car.
As long as we are in default language, your image of the car will be visible in frontend.

Now, create a translation of your car in backend. The car and all related records, also FAL, will be translated.
In frontend you will see your translated car, but with the image of default language.

Back in the backend you delete the image of your translated record and assign a new one.

Possibility A: sys_language_mode = content_fallback
In frontend you will see your translated record, but with image of default language

Possibility B: sys_language_mode = strict
In frontend you will see your translated record and there is NO image anymore.

What does it do?
----------------

This extension hooks into the extbase core and catches all query objects which tries to access sys_file_reference table.
It gets the UID of the translated record (f.e. car) and starts an additional query to collect all
sys_file_reference records which are assigned to that UID.

Further it merges the records from above with the records, which are translated the first time via TYPO3 backend.

What is the difference to faltranslation?
-----------------------------------------

faltranslation replaces 4 methods of the extbase core. IMO this should always be the last try. It's always better to
change core feature by SignalSlots like I do.
Further faltranslation can only access images/files which do NOT have a record in default language. My extension
reads both: records with AND without a parent.

Other extensions
----------------

TYPO3-API
"""""""""

All extension which work directly with TYPO3-API (like tx_news) can not show translated images currently. In that case
repair_translation will help you.

ws_flexslider
"""""""""""""

ws_flexslider does not work with translated records. It does not translate the tt_content-record, before getting
the images. I have created a pull request at Github. So when they merge my request, ws_flexslider will also work with
repair_translation

`PullRequest <https://github.com/svewap/ws_flexslider/pull/17>`_
