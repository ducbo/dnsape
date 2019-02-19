<?php

namespace Iodev\Whois\Modules\Tld\Parsers;

use Iodev\Whois\Helpers\GroupFilter;
use Iodev\Whois\Modules\Tld\DomainInfo;
use Iodev\Whois\Modules\Tld\DomainResponse;
use Iodev\Whois\Modules\Tld\TldParser;

class BlockParser extends CommonParser
{
    /** @var array */
    protected $domainSubsets = [];

    /** @var array */
    protected $primarySubsets = [];

    /** @var array */
    protected $statesSubsets = [];

    /** @var array */
    protected $nameServersSubsets = [];

    /** @var array */
    protected $nameServersSparsedSubsets = [];

    /** @var array */
    protected $ownerSubsets = [];

    /** @var array */
    protected $registrarSubsets = [];

    /** @var array */
    protected $registrarReservedSubsets = [];

    /** @var array */
    protected $registrarReservedKeys = [];

    /** @var array */
    protected $contactSubsets = [];

    /** @var array */
    protected $contactOrgKeys = [];

    /** @var array */
    protected $registrarGroupKeys = [];

    /**
     * @return string
     */
    public function getType()
    {
        return TldParser::BLOCK;
    }

    /**
     * @param DomainResponse $response
     * @return DomainInfo
     */
    public function parseResponse(DomainResponse $response)
    {
        $groups = $this->groupsFromText($response->getText());
        $rootFilter = GroupFilter::create($groups)
            ->useIgnoreCase(true)
            ->setHeaderKey($this->headerKey)
            ->setDomainKeys($this->domainKeys)
            ->setSubsetParams([ '$domain' => $response->getDomain() ]);

        $domainFilter = $rootFilter->cloneMe()
            ->useMatchFirstOnly(true)
            ->filterHasSubsetOf($this->domainSubsets);

        $primaryFilter = $rootFilter->cloneMe()
            ->useMatchFirstOnly(true)
            ->filterHasSubsetOf($this->primarySubsets)
            ->useFirstGroupOr($domainFilter->getFirstGroup());

        $info = new DomainInfo($response, [
            "domainName" => $this->parseDomain($domainFilter),
            "states" => $this->parseStates($rootFilter, $primaryFilter),
            "nameServers" => $this->parseNameServers($rootFilter, $primaryFilter),
            "owner" => $this->parseOwner($rootFilter, $primaryFilter),
            "registrar" => $this->parseRegistrar($rootFilter, $primaryFilter),
            "creationDate" => $this->parseCreationDate($rootFilter, $primaryFilter),
            "expirationDate" => $this->parseExpirationDate($rootFilter, $primaryFilter),
            "whoisServer" => $this->parseWhoisServer($rootFilter, $primaryFilter),
        ], $this->getType());
        return $info->isValuable($this->notRegisteredStatesDict) ? $info : null;
    }

    /**
     * @param GroupFilter $domainFilter
     * @return string
     */
    protected function parseDomain(GroupFilter $domainFilter)
    {
        $domain = $domainFilter->toSelector()
            ->selectKeys($this->domainKeys)
            ->mapAsciiServer()
            ->removeEmpty()
            ->getFirst('');

        if (!empty($domain)) {
            return $domain;
        }
        return $domainFilter->cloneMe()
            ->filterHasHeader()
            ->toSelector()
            ->selectKeys([ 'name' ])
            ->mapAsciiServer()
            ->removeEmpty()
            ->getFirst('');
    }

    /**
     * @param GroupFilter $rootFilter
     * @param GroupFilter $primaryFilter
     * @return array
     */
    protected function parseStates(GroupFilter $rootFilter, GroupFilter $primaryFilter)
    {
        $states = $primaryFilter->toSelector()
            ->selectKeys($this->statesKeys)
            ->mapStates()
            ->removeDuplicates()
            ->getAll();

        if (!empty($states)) {
            return $states;
        }
        return $rootFilter->cloneMe()
            ->useMatchFirstOnly(true)
            ->filterHasSubsetOf($this->statesSubsets)
            ->toSelector()
            ->selectKeys($this->statesKeys)
            ->mapStates()
            ->removeDuplicates()
            ->getAll();
    }

    /**
     * @param GroupFilter $rootFilter
     * @param GroupFilter $primaryFilter
     * @return array
     */
    protected function parseNameServers(GroupFilter $rootFilter, GroupFilter $primaryFilter)
    {
        $nameServers = $rootFilter->cloneMe()
            ->useMatchFirstOnly(true)
            ->filterHasSubsetOf($this->nameServersSubsets)
            ->useFirstGroup()
            ->toSelector()
            ->selectKeys($this->nameServersKeys)
            ->selectKeyGroups($this->nameServersKeysGroups)
            ->mapAsciiServer()
            ->removeEmpty()
            ->getAll();

        $nameServers = $rootFilter->cloneMe()
            ->filterHasSubsetOf($this->nameServersSparsedSubsets)
            ->toSelector()
            ->useMatchFirstOnly(true)
            ->selectItems($nameServers)
            ->selectKeys($this->nameServersKeys)
            ->selectKeyGroups($this->nameServersKeysGroups)
            ->mapAsciiServer()
            ->removeEmpty()
            ->removeDuplicates()
            ->getAll();

        if (!empty($nameServers)) {
            return $nameServers;
        }
        return $primaryFilter->toSelector()
            ->useMatchFirstOnly(true)
            ->selectKeys($this->nameServersKeys)
            ->selectKeyGroups($this->nameServersKeysGroups)
            ->mapAsciiServer()
            ->removeEmpty()
            ->removeDuplicates()
            ->getAll();
    }

    /**
     * @param GroupFilter $rootFilter
     * @param GroupFilter $primaryFilter
     * @return string
     */
    protected function parseOwner(GroupFilter $rootFilter, GroupFilter $primaryFilter)
    {
        $owner = $rootFilter->cloneMe()
            ->useMatchFirstOnly(true)
            ->filterHasSubsetOf($this->ownerSubsets)
            ->toSelector()
            ->selectKeys($this->ownerKeys)
            ->getFirst('');

        if (empty($owner)) {
            $owner = $primaryFilter->toSelector()
                ->selectKeys($this->ownerKeys)
                ->getFirst('');
        }
        if (!empty($owner)) {
            $owner = $rootFilter->cloneMe()
                ->setSubsetParams(['$id' => $owner])
                ->useMatchFirstOnly(true)
                ->filterHasSubsetOf($this->contactSubsets)
                ->toSelector()
                ->selectKeys($this->contactOrgKeys)
                ->selectItems([ $owner ])
                ->removeEmpty()
                ->getFirst('');
        }
        return $owner;
    }

    /**
     * @param GroupFilter $rootFilter
     * @param GroupFilter $primaryFilter
     * @return string
     */
    protected function parseRegistrar(GroupFilter $rootFilter, GroupFilter $primaryFilter)
    {
        $registrar = $primaryFilter->toSelector()
            ->useMatchFirstOnly(true)
            ->selectKeys($this->registrarKeys)
            ->getFirst();

        if (empty($registrar)) {
            $registrarFilter = $rootFilter->cloneMe()
                ->useMatchFirstOnly(true)
                ->filterHasSubsetOf($this->registrarSubsets);

            $registrar = $registrarFilter->toSelector()
                ->selectKeys($this->registrarGroupKeys)
                ->getFirst();
        }
        if (empty($registrar) && !empty($registrarFilter)) {
            $registrar = $registrarFilter->filterHasHeader()
                ->toSelector()
                ->selectKeys([ 'name' ])
                ->getFirst();
        }
        if (empty($registrar)) {
            $registrar = $primaryFilter->toSelector()
                ->selectKeys($this->registrarKeys)
                ->getFirst();
        }

        $regFilter = $rootFilter->cloneMe()
            ->useMatchFirstOnly(true)
            ->filterHasSubsetOf($this->registrarReservedSubsets);

        $regId = $regFilter->toSelector()
            ->selectKeys($this->registrarReservedKeys)
            ->getFirst();

        if (!empty($regId) && (empty($registrar) || $regFilter->getFirstGroup() != $primaryFilter->getFirstGroup())) {
            $registrarOrg = $rootFilter->cloneMe()
                ->setSubsetParams(['$id' => $regId])
                ->useMatchFirstOnly(true)
                ->filterHasSubsetOf($this->contactSubsets)
                ->toSelector()
                ->selectKeys($this->contactOrgKeys)
                ->getFirst();

            $owner = $this->parseOwner($rootFilter, $primaryFilter);
            $registrar = ($registrarOrg && $registrarOrg != $owner)
                ? $registrarOrg
                : $registrar;
        }

        return $registrar;
    }

    /**
     * @param GroupFilter $rootFilter
     * @param GroupFilter $primaryFilter
     * @return int
     */
    protected function parseCreationDate(GroupFilter $rootFilter, GroupFilter $primaryFilter)
    {
        $creationDate = $primaryFilter->toSelector()
            ->selectKeys($this->creationDateKeys)
            ->mapUnixTime()
            ->getFirst(0);

        if (!empty($creationDate)) {
            return $creationDate;
        }
        return $rootFilter->cloneMe()
            ->useMatchFirstOnly(true)
            ->filterHasSubsetKeyOf($this->creationDateKeys)
            ->toSelector()
            ->selectKeys($this->creationDateKeys)
            ->mapUnixTime()
            ->getFirst(0);
    }

    /**
     * @param GroupFilter $rootFilter
     * @param GroupFilter $primaryFilter
     * @return int
     */
    protected function parseExpirationDate(GroupFilter $rootFilter, GroupFilter $primaryFilter)
    {
        $expirationDate = $primaryFilter->toSelector()
            ->selectKeys($this->expirationDateKeys)
            ->mapUnixTime()
            ->getFirst();

        if (!empty($expirationDate)) {
            return $expirationDate;
        }
        return $rootFilter->cloneMe()
            ->useMatchFirstOnly(true)
            ->filterHasSubsetKeyOf($this->expirationDateKeys)
            ->toSelector()
            ->selectKeys($this->expirationDateKeys)
            ->mapUnixTime()
            ->getFirst();
    }

    /**
     * @param GroupFilter $rootFilter
     * @param GroupFilter $primaryFilter
     * @return mixed
     */
    protected function parseWhoisServer(GroupFilter $rootFilter, GroupFilter $primaryFilter)
    {
        return $primaryFilter->toSelector()
            ->selectKeys($this->whoisServerKeys)
            ->mapAsciiServer()
            ->getFirst('');
    }
}
