<?php

/**
 * AG Spam Finder
 *
 * @author Brett Constable
 * @author Andrew McNaughton
 */
class CRM_Customsearches_Form_Search_agSpam extends CRM_Contact_Form_Search_Custom_Base implements CRM_Contact_Form_Search_Interface {

  protected $_formValues;

  function __construct(&$formValues) {
    $this->_formValues = $formValues;

    $this->_columns = array(
      ts('Contact Id') => 'contact_id',
      ts('First') => 'first_name',
      ts('Last') => 'last_name',
      ts('Email') => 'email',
      ts('Phone') => 'phone',
      ts('Postcode') => 'postcode',
    );
    parent::__construct($formValues);
  }

  /**
   * Prepare a set of search fields
   *
   * @param CRM_Core_Form $form modifiable
   * @return void
   */
  function buildForm(&$form) {
    $this->setTitle('AG - Find Potential Spam Records');

    /**
     * Define the search form fields here
     */
    $spam_options = array(
      '1' => ts('First name equals last name'),
      '2' => ts('Names contain numbers'),
      // '3' => ts('Questionable email addresses'),
      // '4' => ts('Questionable phone numbers by length'),
      // '5' => ts('Long postcodes'),
      '6' => ts('MiXEd cAsE LAsT namE'),
      '7' => ts('Non-Numeric Postcode (and address not known to be overseas)'),
      '8' => ts('Long or short Postcode'),
      '9' => ts('Long or short Phone Number'),
      '10' => ts('Unexpected Punctuation in Name'),

    );
    $form->addRadio('spam_options',
      ts('Search options'),
      $spam_options,
      TRUE, '<br />', TRUE
    );

    // Text box for phone number length to test
    $form->add('text',
      'min_length',
      ts('Shortest length of postcodes (default: 4) or phone numbers (default: 10)')
    );

    // Text box for phone number length to test
    $form->add('text',
      'max_length',
      ts('Longest length of postcodes (default: 4) or phone numbers (default: 14)')
    );

    // Date for records added since
    $form->addDate('start_date', ts('Contact records added since'), FALSE, array('formatType' => 'custom'));

    // Filter on Minimum Contact ID
    $form->add('text',
      'min_contact_id',
      ts('Only show contact records with ID greater than:')
    );

    // Filter out blank names
    $form->add('checkbox',
      'blank_names',
      ts("Check to not display contacts whose first and last name are both blank")
    );


    /**
     * If you are using the sample template, this array tells the template fields to render
     * for the search form.
     */
    $form->assign('elements', array(
      // 'spam_options', 'phone_length', 'postcode_length', 'start_date',
      'spam_options',
      'min_contact_id',
      'blank_names',
      'min_length',
      'max_length',
    ));
  }

  /**
   * Define the smarty template used to layout the search form and results listings.
   */
  function templateFile() {
    return 'CRM/Contact/Form/Search/Custom.tpl';
  }

  /**
   * Construct a full SQL query which returns one page worth of results
   *
   * @param int $offset
   * @param int $rowcount
   * @param null $sort
   * @param bool $includeContactIDs
   * @param bool $justIDs
   * @return string, sql
   */
  function all($offset = 0, $rowcount = 0, $sort = NULL, $includeContactIDs = FALSE, $justIDs = FALSE) {

    // SELECT clause must include contact_id as an alias for civicrm_contact.id
    if ($justIDs) {
      $select = "contact_a.id as contact_id";
    }
    else {
      $select = '
                contact_a.id as contact_id,
                contact_a.first_name  as first_name,
                contact_a.last_name   as last_name,
                email.email         as email,
                phone.phone         as phone,
                address.postal_code as postcode';
    }

    $from = $this->from();

    $where = $this->where($includeContactIDs);


    $sql = "
      SELECT $select
      FROM $from\n";

    if (!empty($where)) {
      $sql .= "WHERE $where";
    }

    if ($rowcount > 0 && $offset >= 0) {
      $sql .= " LIMIT $offset, $rowcount ";
    }
    return $sql;
  }

  // from
  function from() {

    $this->buildACLClause('contact_a');

    $sql = "civicrm_contact AS contact_a
      LEFT JOIN civicrm_email email
        ON contact_a.id = email.contact_id and email.is_primary=1
      LEFT JOIN civicrm_address address
        ON contact_a.id = address.contact_id AND address.is_primary=1
      LEFT JOIN civicrm_phone phone
        ON contact_a.id = phone.contact_id AND phone.is_primary=1
        {$this->_aclFrom}";


    return $sql;
  }

  /**
   * WHERE clause is an array built from any required JOINS plus conditional filters based on search criteria field values
   *
   */
  function where($includeContactIDs = FALSE) {
    $clauses = array();

    $clauses [] = '(not contact_a.is_deleted)';
    if ($this->_aclWhere) {
      $clauses[] = "{$this->_aclWhere}";
    }

    $min_length = $this->_formValues['min_length'] + 0;
    $min_length < 10000 ? $min_length : 0;
    $max_length = $this->_formValues['max_length'] + 0;
    $max_length > 0 ? $max_length : 10000;
    $unexpected_name_chars = <<<END_SQL
  concat(
    '[', char(1),'-',char(31),
    char(33),'-',char(37),
    char(40),'-',char(43),
    char(58),'-',char(64),
    "\\\\", char(91),'-',char(96),
    char(123),'-',char(127) ,
    ']'
  )
END_SQL;

    // add contact name search; search on primary name, source contact, assignee
    $spam_option = $this->_formValues['spam_options'];
    switch ($spam_option) {
      case 1:
        // First name equals last name
        $clauses[] = "(contact_a.first_name = contact_a.last_name)";
        break;
      case 2:
        // Names contain numbers
        $clauses[] = "(contact_a.first_name rlike '[0-9]')";
        break;
      case 3:
        // Questionable email addresses
        $clauses[] = "(contact_a.first_name = contact_a.last_name)";
        break;
      case 4:
        // Long postcodes
        $clauses[] = "(contact_a.first_name = contact_a.last_name)";
        break;
      case 5:
        // Questionable phone numbers by length
        $clauses[] = "(contact_a.first_name = contact_a.last_name)";
        break;
      case 6:
        // MiXEd CasE lASt NaME
        $clauses[] = "(contact_a.last_name rlike BINARY '[a-z][A-Z]+[a-z]+[A-Z]')";
        break;
      case 7:
        // Non-Numeric Postcode
        $clauses[] = "((address.country_id = 1013 OR address.country_id IS NULL) AND (address.postal_code rlike '[^0-9 ]'))";
        break;
      case 8:
        // Long or short Postcode
        $clauses[] = "(length(address.postal_code) < $min_length OR length(address.postal_code) > $max_length )";
        break;
      case 9:
        // Long or short Phone Number
        $clauses[] = "(length(phone) < $min_length OR length(phone) > $max_length )";
        break;
      case 10:
        // Unexpected Punctuation in Name
        $clauses[] = <<<END_SQL
            ((contact_a.first_name rlike $unexpected_name_chars)
          OR (contact_a.last_name rlike $unexpected_name_chars)
            )
END_SQL;
        break;
    }

    $min_contact_id = $this->_formValues['min_contact_id'];
    if ($min_contact_id) {
      $clauses [] = "(contact_a.id > $min_contact_id)";
    }

    $blank_names = $this->_formValues['blank_names'];
    if ($blank_names) {
      $clauses [] = "(contact_a.first_name AND contact_a.last_name)";
    }


    if ($includeContactIDs) {
      $contactIDs = array();
      foreach ($this->_formValues as $id => $value) {
        if ($value &&
          substr($id, 0, CRM_Core_Form::CB_PREFIX_LEN) == CRM_Core_Form::CB_PREFIX
        ) {
          $contactIDs[] = substr($id, CRM_Core_Form::CB_PREFIX_LEN);
        }
      }

      if (!empty($contactIDs)) {
        $contactIDs = implode(', ', $contactIDs);
        $clauses[] = "contact_a.id IN ( $contactIDs )";
      }
    }

    return implode(' AND ', $clauses);
  }

  /*
   * Functions below generally don't need to be modified
   */
  function count() {
    $sql = $this->all();

    $dao = CRM_Core_DAO::executeQuery($sql,
      CRM_Core_DAO::$_nullArray
    );
    return $dao->N;
  }

  function contactIDs($offset = 0, $rowcount = 0, $sort = NULL) {
    return $this->all($offset, $rowcount, $sort, FALSE, TRUE);
  }

  function &columns() {
    return $this->_columns;
  }

  function summary() {
    return NULL;
  }

  function buildACLClause($tableAlias = 'contact') {
    list($this->_aclFrom, $this->_aclWhere) = CRM_Contact_BAO_Contact_Permission::cacheClause($tableAlias);
  }

}
