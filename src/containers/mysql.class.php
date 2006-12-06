<?php
/**
 * src/container/mysql.class.php
 *
 * Copyright © 2006 Stephane Gully <stephane.gully@gmail.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful, 
 * but WITHOUT ANY WARRANTY; without even the implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details. 
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the
 * Free Software Foundation, 51 Franklin St, Fifth Floor,
 * Boston, MA  02110-1301  USA
 */

require_once dirname(__FILE__)."/../pfccontainer.class.php";

/**
 * pfcContainer_Mysql is a concret container which store data into mysql
 *
 * Because of the new storage functions (setMeta, getMeta, rmMeta) 
 * everything can be stored in just one single table.
 * Using type "HEAP" or "MEMORY" mysql loads this table into memory making it very fast
 * There is no routine to create the table if it does not exists so you have to create it by hand
 * Replace the database login info at the top of pfcContainer_mysql class with your own 
 * You also need some config lines in your chat index file:
 * $params["container_type"] = "mysql";
 * $params["container_cfg_mysql_host"] = "localhost";
 * $params["container_cfg_mysql_port"] = 3306; 
 * $params["container_cfg_mysql_database"] = "phpfreechat"; 
 * $params["container_cfg_mysql_table"] = "phpfreechat"; 
 * $params["container_cfg_mysql_username"] = "root"; 
 * $params["container_cfg_mysql_password"] = ""; 
 *
 * @author Stephane Gully <stephane.gully@gmail.com>
 * @author HenkBB
 */
class pfcContainer_Mysql extends pfcContainer
{
  var $_db = null;
  var $_sql_create_table = "
 CREATE TABLE IF NOT EXISTS `%table%` (
  `server` varchar(256) NOT NULL default '',
  `group` varchar(256) NOT NULL default '',
  `subgroup` varchar(256) NOT NULL default '',
  `leaf` varchar(256) NOT NULL default '',
  `leafvalue` varchar(1024) NOT NULL,
  `timestamp` int(11) NOT NULL default 0,
  PRIMARY KEY  (`server`,`group`,`subgroup`,`leaf`)
) ENGINE=MEMORY;";
    
  function pfcContainer_Mysql(&$config)
  {
    pfcContainer::pfcContainer($config);
  }

  function getDefaultConfig()
  {
    $c =& $this->c;    
    $cfg = pfcContainer::getDefaultConfig();
    $cfg["mysql_host"] = 'localhost';
    $cfg["mysql_port"] = 3306;
    $cfg["mysql_database"] = 'phpfreechat';
    $cfg["mysql_table"]    = 'phpfreechat';
    $cfg["mysql_username"] = 'root';
    $cfg["mysql_password"] = '';
    return $cfg;
  }

  function init()
  {
    $errors = pfcContainer::init();
    $c =& $this->c;

    // connect to the db
    $db = $this->_connect();
    if ($db === FALSE)
    {
      $errors[] = _pfc("Mysql container: connect error");
      return $errors;
    }

    // create the db if it doesn't exists
    $db_exists = false;
    $db_list = mysql_list_dbs($db);
    while (!$db_exists && $row = mysql_fetch_object($db_list))
      $db_exists = ($c->container_cfg_mysql_database == $row->Database);
    if (!$db_exists)
    {
      $query = 'CREATE DATABASE '.$c->container_cfg_mysql_database;
      $result = mysql_query($query, $db);
      if ($result === FALSE)
      {
        $errors[] = _pfc("Mysql container: create database error '%s'",mysql_error($db));
        return $errors;
      }
      mysql_select_db($c->container_cfg_mysql_database, $db);
    }
    
    // create the table if it doesn't exists
    $query = str_replace('%table%',$c->container_cfg_mysql_table,$this->_sql_create_table);
    $result = mysql_query($query, $db);
    if ($result === FALSE)
    {
      $errors[] = _pfc("Mysql container: create table error '%s'",mysql_error($db));
      return $errors;
    }
    return $errors;
  }

  function _connect()
  {
    if (!$this->_db)
    {
      $c =& $this->c;
      $this->_db = mysql_pconnect($c->container_cfg_mysql_host.':'.$c->container_cfg_mysql_port,
                                  $c->container_cfg_mysql_username,
                                  $c->container_cfg_mysql_password);
      mysql_select_db($c->container_cfg_mysql_database, $this->_db);
    }
    return $this->_db;
  }

  function setMeta($group, $subgroup, $leaf, $leafvalue = NULL)
  {
    $c =& $this->c;

    if ($c->debug)
      file_put_contents("/tmp/debug.txt", "\nsetMeta(".$group.",".$subgroup.",".$leaf.",".$leafvalue.")", FILE_APPEND);
    
    $server = $c->serverid;    
    $db = $this->_connect();

    if ($leafvalue == NULL){$leafvalue="";};
    
    $sql_select = "SELECT * FROM ".$c->container_cfg_mysql_table." WHERE `server`='$server' AND `group`='$group' AND `subgroup`='$subgroup' AND `leaf`='$leaf'";
    $sql_insert="REPLACE INTO ".$c->container_cfg_mysql_table." (`server`, `group`, `subgroup`, `leaf`, `leafvalue`, `timestamp`) VALUES('$server', '$group', '$subgroup', '$leaf', '".addslashes($leafvalue)."', '".time()."')";
    $sql_update="UPDATE ".$c->container_cfg_mysql_table." SET `leafvalue`='".addslashes($leafvalue)."', `timestamp`='".time()."' WHERE  `server`='$server' AND `group`='$group' AND `subgroup`='$subgroup' AND `leaf`='$leaf'";
    
    $res = mysql_query($sql_select, $db);
    if( !(mysql_num_rows($res)>0) )
    {
      if ($c->debug)
        file_put_contents("/tmp/debug.txt", "\nsetSQL(".$sql_insert.")", FILE_APPEND);

      mysql_query($sql_insert, $db);
      return 0; // value created
    }
    else
    {
      if ($sql_update != "")
      {
        if ($c->debug)
          file_put_contents("/tmp/debug.txt", "\nsetSQL(".$sql_update.")", FILE_APPEND);
        
        mysql_query($sql_update, $db);
      }
      return 1; // value overwritten
    }
  }

  
  function getMeta($group, $subgroup = null, $leaf = null, $withleafvalue = false)
  {
    $c =& $this->c;
    if ($c->debug)
      file_put_contents("/tmp/debug.txt", "\ngetMeta(".$group.",".$subgroup.",".$leaf.",".$withleafvalue.")", FILE_APPEND);
    
    $ret = array();
    $ret["timestamp"] = array();
    $ret["value"]     = array();
    
    $server = $c->serverid;    
    $db = $this->_connect();
    
    $sql_where="";
    $value="leafvalue";
    
    if ($group != NULL)
    {
      $sql_where.=" AND `group`='$group'";
      $value="subgroup";        
    }    
    
    if ($subgroup != NULL)
    {
      $sql_where.=" AND `subgroup`='$subgroup'";
      $value="leaf";        
    }
    
    if ($leaf != NULL)
    {
      $sql_where.=" AND `leaf`='$leaf'";
      $value="leafvalue";    
    }
    
    $sql_select="SELECT `$value`, `timestamp` FROM ".$c->container_cfg_mysql_table." WHERE `server`='$server' $sql_where GROUP BY `$value` ORDER BY timestamp";    
    
    if ($c->debug)
      file_put_contents("/tmp/debug.txt", "\ngetSQL(".$sql_select.")", FILE_APPEND);
    
    if ($sql_select != "")
    {
      $thisresult = mysql_query($sql_select, $db);
      if (mysql_num_rows($thisresult))
      {
        while ($regel = mysql_fetch_array($thisresult))
        {
          $ret["timestamp"][] = $regel["timestamp"];
          if ($value == "leafvalue")
          {
            if ($withleafvalue)
              $ret["value"][]     = $regel[$value];
            else
              $ret["value"][]     = NULL;
          }
          else
            $ret["value"][] = $regel[$value];
        }
        
      }
      else
        return $ret;
    }
    return $ret;
  }

  function rmMeta($group, $subgroup = null, $leaf = null)
  {
    $c =& $this->c;
    if ($c->debug)
      file_put_contents("/tmp/debug.txt", "\nrmMeta(".$group.",".$subgroup.",".$leaf.")", FILE_APPEND);
    
    $server = $c->serverid;    
    $db = $this->_connect();
    
    $sql_delete = "DELETE FROM ".$c->container_cfg_mysql_table." WHERE `server`='$server'";
    
    if($group != NULL)
      $sql_delete .= " AND `group`='$group'";
    
    if($subgroup != NULL)
      $sql_delete .= " AND `subgroup`='$subgroup'";

    if ($leaf != NULL)
      $sql_delete .= " AND `leaf`='$leaf'";
    
    if ($c->debug)
      file_put_contents("/tmp/debug.txt", "\nrmSQL(".$sql_delete.")", FILE_APPEND);
    
    mysql_query($sql_delete, $db);
    return true;
  }

  function encode($str)
  {
    return addslashes(urlencode($str));
  }
  
  function decode($str)
  {
    return urldecode(stripslashes($str));
  }
  
}

?>