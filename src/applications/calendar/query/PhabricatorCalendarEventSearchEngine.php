<?php

final class PhabricatorCalendarEventSearchEngine
  extends PhabricatorApplicationSearchEngine {

  private $calendarYear;
  private $calendarMonth;

  public function getResultTypeDescription() {
    return pht('Calendar Events');
  }

  public function getApplicationClassName() {
    return 'PhabricatorCalendarApplication';
  }

  public function buildSavedQueryFromRequest(AphrontRequest $request) {
    $saved = new PhabricatorSavedQuery();

    $saved->setParameter(
      'rangeStart',
      $this->readDateFromRequest($request, 'rangeStart'));

    $saved->setParameter(
      'rangeEnd',
      $this->readDateFromRequest($request, 'rangeEnd'));

    $saved->setParameter(
      'upcoming',
      $this->readBoolFromRequest($request, 'upcoming'));

    $saved->setParameter(
      'invitedPHIDs',
      $this->readUsersFromRequest($request, 'invited'));

    $saved->setParameter(
      'creatorPHIDs',
      $this->readUsersFromRequest($request, 'creators'));

    $saved->setParameter(
      'isCancelled',
      $request->getStr('isCancelled'));

    $saved->setParameter(
      'display',
      $request->getStr('display'));

    return $saved;
  }

  public function buildQueryFromSavedQuery(PhabricatorSavedQuery $saved) {
    $query = id(new PhabricatorCalendarEventQuery());
    $viewer = $this->requireViewer();

    $min_range = null;
    $max_range = null;

    if ($saved->getParameter('rangeStart')) {
      $min_range = $saved->getParameter('rangeStart');
    }

    if ($saved->getParameter('rangeEnd')) {
      $max_range = $saved->getParameter('rangeEnd');
    }

    if ($saved->getParameter('display') == 'month') {
      list($start_month, $start_year) = $this->getDisplayMonthAndYear($saved);
      $start_day = 1;

      $end_year = ($start_month == 12) ? $start_year + 1 : $start_year;
      $end_month = ($start_month == 12) ? 1 : $start_month + 1;
      $end_day = 1;

      $calendar_start = AphrontFormDateControlValue::newFromParts(
        $viewer,
        $start_year,
        $start_month,
        $start_day)->getEpoch();
      $calendar_end = AphrontFormDateControlValue::newFromParts(
        $viewer,
        $end_year,
        $end_month,
        $end_day)->getEpoch();

      if (!$min_range || ($min_range < $calendar_start)) {
        $min_range = $calendar_start;
      }
      if (!$max_range || ($max_range > $calendar_end)) {
        $max_range = $calendar_end;
      }
    }

    if ($saved->getParameter('upcoming')) {
      if ($min_range) {
        $min_range = max(time(), $min_range);
      } else {
        $min_range = time();
      }
    }

    if ($min_range || $max_range) {
      $query->withDateRange($min_range, $max_range);
    }

    $invited_phids = $saved->getParameter('invitedPHIDs');
    if ($invited_phids) {
      $query->withInvitedPHIDs($invited_phids);
    }

    $creator_phids = $saved->getParameter('creatorPHIDs');
    if ($creator_phids) {
      $query->withCreatorPHIDs($creator_phids);
    }

    $is_cancelled = $saved->getParameter('isCancelled');
    switch ($is_cancelled) {
      case 'active':
        $query->withIsCancelled(false);
        break;
      case 'cancelled':
        $query->withIsCancelled(true);
        break;
    }

    return $query;
  }

  public function buildSearchForm(
    AphrontFormView $form,
    PhabricatorSavedQuery $saved) {

    $range_start = $saved->getParameter('rangeStart');
    $range_end = $saved->getParameter('rangeEnd');
    $upcoming = $saved->getParameter('upcoming');
    $is_cancelled = $saved->getParameter('isCancelled', 'active');
    $display = $saved->getParameter('display', 'month');

    $invited_phids = $saved->getParameter('invitedPHIDs', array());
    $creator_phids = $saved->getParameter('creatorPHIDs', array());
    $resolution_types = array(
      'active' => pht('Active Events Only'),
      'cancelled' => pht('Cancelled Events Only'),
      'both' => pht('Both Cancelled and Active Events'),
    );
    $display_options = array(
      'month' => pht('Month View'),
      'list' => pht('List View'),
    );

    $form
      ->appendControl(
        id(new AphrontFormTokenizerControl())
          ->setDatasource(new PhabricatorPeopleDatasource())
          ->setName('creators')
          ->setLabel(pht('Created By'))
          ->setValue($creator_phids))
      ->appendControl(
        id(new AphrontFormTokenizerControl())
          ->setDatasource(new PhabricatorPeopleDatasource())
          ->setName('invited')
          ->setLabel(pht('Invited'))
          ->setValue($invited_phids))
      ->appendChild(
        id(new AphrontFormDateControl())
          ->setLabel(pht('Occurs After'))
          ->setUser($this->requireViewer())
          ->setName('rangeStart')
          ->setAllowNull(true)
          ->setValue($range_start))
      ->appendChild(
        id(new AphrontFormDateControl())
          ->setLabel(pht('Occurs Before'))
          ->setUser($this->requireViewer())
          ->setName('rangeEnd')
          ->setAllowNull(true)
          ->setValue($range_end))
      ->appendChild(
        id(new AphrontFormCheckboxControl())
          ->addCheckbox(
            'upcoming',
            1,
            pht('Show only upcoming events.'),
            $upcoming))
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel(pht('Cancelled Events'))
          ->setName('isCancelled')
          ->setValue($is_cancelled)
          ->setOptions($resolution_types))
      ->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel(pht('Display Options'))
          ->setName('display')
          ->setValue($display)
          ->setOptions($display_options));
  }

  protected function getURI($path) {
    return '/calendar/'.$path;
  }

  protected function getBuiltinQueryNames() {
    $names = array(
      'month' => pht('Month View'),
      'upcoming' => pht('Upcoming Events'),
      'all' => pht('All Events'),
    );

    return $names;
  }

  public function setCalendarYearAndMonth($year, $month) {
    $this->calendarYear = $year;
    $this->calendarMonth = $month;

    return $this;
  }

  public function buildSavedQueryFromBuiltin($query_key) {
    $query = $this->newSavedQuery();
    $query->setQueryKey($query_key);

    switch ($query_key) {
      case 'month':
        return $query->setParameter('display', 'month');
      case 'upcoming':
        return $query->setParameter('upcoming', true);
      case 'all':
        return $query;
    }

    return parent::buildSavedQueryFromBuiltin($query_key);
  }

  protected function getRequiredHandlePHIDsForResultList(
    array $objects,
    PhabricatorSavedQuery $query) {
    $phids = array();
    foreach ($objects as $event) {
      $phids[$event->getUserPHID()] = 1;
    }
    return array_keys($phids);
  }

  protected function renderResultList(
    array $events,
    PhabricatorSavedQuery $query,
    array $handles) {

    if ($query->getParameter('display') == 'month') {
      return $this->buildCalendarView($events, $query, $handles);
    }

    assert_instances_of($events, 'PhabricatorCalendarEvent');
    $viewer = $this->requireViewer();
    $list = new PHUIObjectItemListView();
    foreach ($events as $event) {
      $href = '/E'.$event->getID();
      $from = phabricator_datetime($event->getDateFrom(), $viewer);
      $to   = phabricator_datetime($event->getDateTo(), $viewer);
      $creator_handle = $handles[$event->getUserPHID()];

      $name = (strlen($event->getName())) ?
        $event->getName() : $event->getTerseSummary($viewer);

      $color = ($event->getStatus() == PhabricatorCalendarEvent::STATUS_AWAY)
        ? 'red'
        : 'yellow';

      $item = id(new PHUIObjectItemView())
        ->setHeader($name)
        ->setHref($href)
        ->setBarColor($color)
        ->addByline(pht('Creator: %s', $creator_handle->renderLink()))
        ->addAttribute(pht('From %s to %s', $from, $to))
        ->addAttribute(id(new PhutilUTF8StringTruncator())
          ->setMaximumGlyphs(64)
          ->truncateString($event->getDescription()));

      $list->addItem($item);
    }

    return $list;
  }

  private function buildCalendarView(
    array $statuses,
    PhabricatorSavedQuery $query,
    array $handles) {
    $viewer = $this->requireViewer();
    $now = time();

    list($start_month, $start_year) = $this->getDisplayMonthAndYear($query);

    $now_year  = phabricator_format_local_time($now, $viewer, 'Y');
    $now_month = phabricator_format_local_time($now, $viewer, 'm');
    $now_day   = phabricator_format_local_time($now, $viewer, 'j');

    if ($start_month == $now_month && $start_year == $now_year) {
      $month_view = new PHUICalendarMonthView(
        $start_month,
        $start_year,
        $now_day);
    } else {
      $month_view = new PHUICalendarMonthView(
        $start_month,
        $start_year);
    }

    $month_view->setUser($viewer);

    $phids = mpull($statuses, 'getUserPHID');

    /* Assign Colors */
    $unique = array_unique($phids);
    $allblue = false;
    $calcolors = CalendarColors::getColors();
    if (count($unique) > count($calcolors)) {
      $allblue = true;
    }
    $i = 0;
    $eventcolor = array();
    foreach ($unique as $phid) {
      if ($allblue) {
        $eventcolor[$phid] = CalendarColors::COLOR_SKY;
      } else {
        $eventcolor[$phid] = $calcolors[$i];
      }
      $i++;
    }

    foreach ($statuses as $status) {
      $event = new AphrontCalendarEventView();
      $event->setEpochRange($status->getDateFrom(), $status->getDateTo());

      $name_text = $handles[$status->getUserPHID()]->getName();
      $status_text = $status->getHumanStatus();
      $event->setUserPHID($status->getUserPHID());
      $event->setDescription(pht('%s (%s)', $name_text, $status_text));
      $event->setName($status_text);
      $event->setEventID($status->getID());
      $event->setColor($eventcolor[$status->getUserPHID()]);
      $month_view->addEvent($event);
    }

    $month_view->setBrowseURI(
      $this->getURI('query/'.$query->getQueryKey().'/'));

    return $month_view;
  }

  private function getDisplayMonthAndYear(
    PhabricatorSavedQuery $query) {
    $viewer = $this->requireViewer();

    // get month/year from url
    if ($this->calendarYear && $this->calendarMonth) {
      $start_year = $this->calendarYear;
      $start_month = $this->calendarMonth;
    } else {
      $epoch = $query->getParameter('rangeStart');
      if (!$epoch) {
        $epoch = $query->getParameter('rangeEnd');
        if (!$epoch) {
          $epoch = time();
        }
      }
      $start_year  = phabricator_format_local_time($epoch, $viewer, 'Y');
      $start_month = phabricator_format_local_time($epoch, $viewer, 'm');
    }

    return array($start_month, $start_year);
  }

  public function getPageSize(PhabricatorSavedQuery $saved) {
    return $saved->getParameter('limit', 1000);
  }

}
