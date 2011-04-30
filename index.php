<?php

spl_autoload_register(function($class)
{
    $file = __DIR__.'/lib/Predis/lib/'.strtr($class, '\\', '/').'.php';

    if (file_exists($file)) {
        require $file;
        return true;
    }
});


$redis = new Predis\Client(array(
    'host'     => '127.0.0.1',
    'port'     => 6379,
));

$redis->flushall();


//

function postEntry(Predis\Client $redis, $members, $owner, $text, $date = null, $type = 'STATUS')
{
    $entry = array(
        'id'    => uniqid('', true),
        'type'  => $type,
        'date'  => $date ?: microtime(true),
        'text'  => $text,
        //'owner' => $owner,
    );


    $entryStruct = json_encode($entry);

    $redis->hset('member.' . $owner['id'] . '.entries', $entry['id'], $entryStruct);

    $redis->zadd('member.' . $owner['id'] . '.walls.ownerWall', $entry['date'], $entry['id']);
    $redis->zadd('member.' . $owner['id'] . '.walls.viewerWall', $entry['date'], $entry['id']);

    foreach ($owner['contacts'] as $contactId) {
        $contact = $members[$contactId];

        if ($contact['type'] === 'PARTNER') {
            continue;
        }

        $redis->hset('member.' . $contact['id'] . '.entries', $entry['id'], $entryStruct);

        $redis->zadd('member.' . $contact['id'] . '.walls.ownerWall', $entry['date'], $entry['id']);
    }
}

function getEntriesForViewer(Predis\Client $redis, $members, $owner, $viewer)
{
}

function getEntriesForOwner(Predis\Client $redis, $owner, $maxResults, $lastEntry = null)
{
    $lastDate = $lastEntry ? $lastEntry['date'] : null;

    $callback = function($maxResults, $lastDate) use(&$redis, &$owner) {
        $redisResult = $redis->hmget(
            'member.' . $owner['id'] . '.entries',
            $redis->zrevrangebyscore(
                'member.' . $owner['id'] . '.walls.ownerWall',
                ($lastDate ?: '+inf'),
                '-inf', 'LIMIT',
                '0',
                $maxResults
            )
        );

        $array = array();

        foreach ($redisResult as $row) {
            $array[] = json_decode($row, true);
        }

        return $array;
    };

    $result = new IterableRedisResult($callback, $maxResults, $lastDate);
    return $result;
}


class IterableRedisResult implements Iterator 
{
    private $callback;
    private $maxResults;

    private $index = 0;
    private $array = array();

    public function __construct(Closure $callback, $maxResults, $lastDate)
    {
        $this->callback = $callback;
        $this->maxResults = $maxResults;

        $this->array = $callback($maxResults, $lastDate);
    }

    public function rewind()
    {
        $this->index = 0;
    }

    private function groupNextEntries($currentIndex, &$current)
    {
        $nextIndex = $this->index + 1;
        $next = isset($this->array[$nextIndex]) ? $this->array[$nextIndex] : null;

        if ($next && $next['type'] === 'PHOTO') { // @FIXME doesnt take owner into account
            $current['__group'][] = $next; // group next entries as children
            unset($this->array[$nextIndex]);
            $this->next();
            $this->groupNextEntries($nextIndex, $current);
        }
    }

    public function current()
    {
        $current = $this->array[$this->index];

        if ($current['type'] === 'PHOTO') {
            $this->groupNextEntries($this->index, $current);
        }
        
        return $current;
    }

    public function key()
    {
        return $this->index;
    }

    public function next()
    {
        $this->index++;
    }

    public function valid()
    {
        return $this->index !== $this->maxResults;
    }

    public function getLast()
    {
        return end($this->array);
    }
}




//

$members = array(
    1 => array(
        'id'        => 1,
        'name'      => 'Owner 1',
        'type'      => 'MEMBER',
        'contacts'  => array(2, 3,),
    ),
    2 => array(
        'id'        => 2,
        'name'      => 'Owner 2',
        'type'      => 'MEMBER',
        'contacts'  => array(1, 3,),
    ),
    3 => array(
        'id'        => 3,
        'name'      => 'Owner 3',
        'type'      => 'PARTNER',
        'contacts'  => array(1, 2,),
    ),
);




$ownerId = 1;
$viewerId = 2;

$owner = $members[$ownerId];
$viewer = $members[$viewerId];



// owner posts entry on own wall
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 1');
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 2');
postEntry($redis, $members, $viewer, 'From Owner 2: Some Entry 1');
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 3');
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 4');
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 5');
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 6');
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 7');
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 8');
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 9');
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 10');
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 11');

postEntry($redis, $members, $owner, 'From Owner 1: Photo Entry 1', null, 'PHOTO');
postEntry($redis, $members, $owner, 'From Owner 1: Photo Entry 2', null, 'PHOTO');
postEntry($redis, $members, $owner, 'From Owner 1: Photo Entry 3', null, 'PHOTO');
postEntry($redis, $members, $owner, 'From Owner 1: Some Entry between Photos');
postEntry($redis, $members, $owner, 'From Owner 1: Photo Entry 4', null, 'PHOTO');


// owner views own wall
$entries_page1 = getEntriesForOwner($redis, $owner, 10);

foreach ($entries_page1 as $key => $value) {
    print_r($value);
    print '<br>';
}





postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 12', microtime(true) - 10000);
#postEntry($redis, $members, $owner, 'From Owner 1: Some Entry 13', microtime(true) - 20000);


#postEntry($redis, $members, $owner, 'From Owner 1: Some Entry between Photos', microtime(true) - 30000);
postEntry($redis, $members, $owner, 'From Owner 1: Photo Entry 1', microtime(true) - 40000, 'PHOTO');
postEntry($redis, $members, $owner, 'From Owner 1: Photo Entry 2', microtime(true) - 50000, 'PHOTO');
postEntry($redis, $members, $owner, 'From Owner 1: Photo Entry 3', microtime(true) - 60000, 'PHOTO');


print '<hr>';

$entries_page2 = getEntriesForOwner($redis, $owner, 10, $entries_page1->getLast());

foreach ($entries_page2 as $key => $value) {
    print_r($value);
    print '<br>';
}

print '<hr>';

$entries_page3 = getEntriesForOwner($redis, $owner, 10, $entries_page2->getLast());

foreach ($entries_page3 as $key => $value) {
    print_r($value);
    print '<br>';
}


// contact with access rights views owners wall


