<?php

namespace Apps\Fintech\Packages\Accounting\Tools\Accountshierarchies;

use Apps\Fintech\Packages\Accounting\Tools\Accountshierarchies\Model\AppsFintechAccountingToolsAccountshierarchies;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToListContents;
use System\Base\BasePackage;

class AccountingToolsAccountshierarchies extends BasePackage
{
    protected $modelToUse = AppsFintechAccountingToolsAccountshierarchies::class;

    protected $packageName = 'accountingaccountshierarchies';

    public $accountingaccountshierarchies;

    public function getAccountingAccountshierarchiesById($id)
    {
        $accountingaccountshierarchies = $this->getById($id);

        if ($accountingaccountshierarchies) {
            $this->addResponse('Success', 0, ['hierarchy' => $accountingaccountshierarchies]);

            return;
        }

        $this->addResponse('Error', 1);
    }

    public function addAccountingAccountshierarchies($data)
    {
        if ($this->add($data)) {
            $this->addResponse('Hierarchy added successfully');

            return true;
        }

        $this->addResponse('Error adding hierarchy', 1);

        return false;
    }

    public function updateAccountingAccountshierarchies($data)
    {
        $accountingaccountshierarchies = $this->getById($data['id']);

        if (!$accountingaccountshierarchies) {
            $this->addResponse('Hierarchy not found', 1);

            return false;
        }

        $accountingaccountshierarchies = array_replace($accountingaccountshierarchies, $data);

        if ($this->update($accountingaccountshierarchies)) {
            $this->addResponse('Hierarchy added successfully');

            return true;
        }

        $this->addResponse('Error adding hierarchy', 1);

        return false;
    }

    public function removeAccountingAccountshierarchies($id)
    {
        $accountingaccountshierarchies = $this->getById($id);

        if (!$accountingaccountshierarchies) {
            $this->addResponse('Hierarchy not found', 1);

            return false;
        }

        if ($this->remove($accountingaccountshierarchies['id'])) {
            $this->addResponse('Success');

            return;
        }

        $this->addResponse('Error removing Hierarchy', 1);
    }

    public function processGnucashAccounts()
    {
        //https://github.com/Gnucash/gnucash/tree/stable/data/accounts
        //Accounts are in XML file format in Folder GnucashAccounts/
        try {
            $files = $this->localContent->listContents('apps/Fintech/Packages/Accounting/Tools/Accountshierarchies/GnucashAccounts/')->toArray();

            if (count($files) > 0) {
                include('vendor/Simplehtmldom.php');

                foreach ($files as $file) {
                    $gnuAccounts = [];

                    $filename = str_replace('.gnucash-xea', '', str_replace('acctchrt_', '', $this->helper->last(explode('/', $file->path()))));

                    $fileXMLContent = file_get_html(base_path($file->path()));

                    $accounts = $fileXMLContent->find('gnc:account');

                    if ($accounts && count($accounts) > 0) {
                        foreach ($accounts as $account) {
                            $id = $account->find('act:id');
                            if (isset($id[0])) {
                                if (!isset($gnuAccounts[$id[0]->plaintext])) {
                                    $gnuAccounts[$id[0]->plaintext] = [];
                                }

                                $gnuAccounts[$id[0]->plaintext]['uuid'] = $id[0]->plaintext;
                            }

                            $name = $account->find('act:name');
                            if (isset($name[0])) {
                                $gnuAccounts[$id[0]->plaintext]['name'] = $name[0]->plaintext;
                            }
                            $type = $account->find('act:type');
                            if (isset($type[0])) {
                                $gnuAccounts[$id[0]->plaintext]['type'] = strtolower($type[0]->plaintext);
                            }
                            $description = $account->find('act:description');
                            if (isset($description[0])) {
                                $gnuAccounts[$id[0]->plaintext]['description'] = $description[0]->plaintext;
                            }
                            $parent = $account->find('act:parent');
                            if (isset($parent[0])) {
                                $gnuAccounts[$id[0]->plaintext]['parent'] = $parent[0]->plaintext;
                                if (isset($gnuAccounts[$parent[0]->plaintext])) {
                                    if (!isset($gnuAccounts[$parent[0]->plaintext]['childs'])) {
                                        $gnuAccounts[$parent[0]->plaintext]['childs'] = [];
                                    }

                                    array_push($gnuAccounts[$parent[0]->plaintext]['childs'], $id[0]->plaintext);
                                }
                            }

                            $slots = $account->find('act:slots');
                            if (count($slots) > 0) {
                                foreach ($slots as $slot) {
                                    if (isset($slot->children[0]->children[0]) &&
                                        $slot->children[0]->children[0]->plaintext === 'placeholder'
                                    ) {
                                        $gnuAccounts[$id[0]->plaintext]['type'] = 'placeholder';
                                    }
                                }
                            }
                        }

                        if (count($gnuAccounts) > 0) {
                            $gnuAccountsArr = $this->generateGnuAccountsJson($gnuAccounts);

                            if ($gnuAccountsArr &&
                                count($gnuAccountsArr) > 0
                            ) {
                                if ($this->helper->first($gnuAccountsArr)['title'] === 'Root Account') {
                                    $gnuAccountsArr = $this->helper->first($gnuAccountsArr)['childs'];
                                }

                                $newHierarchy = [];
                                $newHierarchy['name'] = ucfirst($filename);
                                $newHierarchy['description'] = 'Imported via GNUCash file: ' . $this->helper->last(explode('/', $file->path()));
                                $newHierarchy['hierarchy'] = $this->helper->encode($gnuAccountsArr);

                                $this->add($newHierarchy);
                            }
                        }
                    }
                }
            }
        } catch (FilesystemException | UnableToListContents | \throwable $e) {
            trace ([$e]);
        }
    }

    protected function generateGnuAccountsJson($gnuAccounts, $childId = null)
    {
        $gnuAccountsArr = [];

        $seq = 0;

        foreach ($gnuAccounts as $accountId => $account) {
            if ($childId) {
                if ($childId !== $accountId) {
                    continue;
                }

                $key = $gnuAccounts[$childId]['uuid'];
                $account = $gnuAccounts[$childId];
            } else {
                if (!isset($account['childs'])) {
                    continue;
                }

                $key = $account['uuid'];
            }

            if (!isset($gnuAccountsArr[$key])) {
                $gnuAccountsArr[$key] = [];
            }

            if (isset($account['childs']) && count($account['childs']) > 0) {
                $gnuAccountsArr[$key]['title'] = $account['name'];
                $gnuAccountsArr[$key]['icon'] = 'circle';
                $gnuAccountsArr[$key]['data']['type'] = 'placeholder';
                $gnuAccountsArr[$key]['seq'] = $seq;
                $gnuAccountsArr[$key]['data']['uuid'] = $account['uuid'];
                if (isset($account['description'])) {
                    $gnuAccountsArr[$key]['data']['description'] = $account['description'];
                }

                $childs = [];
                foreach ($account['childs'] as $child) {
                    $childsArr = $this->generateGnuAccountsJson($gnuAccounts, $child);
                    $childsArr[$this->helper->firstKey($childsArr)]['seq'] = $seq;
                    $seq++;

                    $childs = array_merge($childs, $childsArr);
                }

                $gnuAccountsArr[$key]['childs'] = $childs;


                break;
            } else {
                $gnuAccountsArr[$key]['title'] = $account['name'];
                $gnuAccountsArr[$key]['icon'] = 'circle';
                if ($account['type'] !== 'placeholder') {
                    $gnuAccountsArr[$key]['icon'] = 'file-lines';
                }
                $gnuAccountsArr[$key]['seq'] = $seq;
                $gnuAccountsArr[$key]['data']['uuid'] = $account['uuid'];
                $gnuAccountsArr[$key]['data']['type'] = $account['type'];
                if (isset($account['description'])) {
                    $gnuAccountsArr[$key]['data']['description'] = $account['description'];
                }

                $seq++;
            }
        }

        return $gnuAccountsArr;
    }
}