<?php

/*

           -
         /   \
      /         \
   /   PocketMine  \
/          MP         \
|\     @shoghicp     /|
|.   \           /   .|
| ..     \   /     .. |
|    ..    |    ..    |
|       .. | ..       |
\          |          /
   \       |       /
      \    |    /
         \ | /

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Lesser General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.


*/


define("ENTITY_PLAYER", 0);
define("ENTITY_MOB", 1);
define("ENTITY_OBJECT", 2);
define("ENTITY_ITEM", 3);
define("ENTITY_PAINTING", 4);

class Entity extends stdClass{
	var $invincible, $dmgcounter, $eid, $type, $name, $x, $y, $z, $speedX, $speedY, $speedZ, $speed, $last = array(0, 0, 0, 0), $yaw, $pitch, $dead, $data, $class, $attach, $metadata, $closed, $player, $onTick;
	private $server;
	function __construct($server, $eid, $class, $type = 0, $data = array()){
		$this->server = $server;
		$this->eid = (int) $eid;
		$this->type = (int) $type;
		$this->class = (int) $class;
		$this->player = false;
		$this->attach = false;
		$this->data = $data;
		$this->status = 0;
		$this->health = 20;
		$this->dmgcounter = array(0, 0);
		$this->invincible = false;
		$this->dead = false;
		$this->closed = false;
		$this->name = "";
		$this->server->query("INSERT OR REPLACE INTO entities (EID, type, class, health) VALUES (".$this->eid.", ".$this->type.", ".$this->class.", ".$this->health.");");
		$this->server->schedule(4, array($this, "update"), array(), true);
		$this->server->schedule(10, array($this, "environmentUpdate"), array(), true);
		$this->metadata = array();
		$this->x = isset($this->data["x"]) ? $this->data["x"]:0;
		$this->y = isset($this->data["y"]) ? $this->data["y"]:0;
		$this->z = isset($this->data["z"]) ? $this->data["z"]:0;
		$this->speedX = isset($this->data["speedX"]) ? $this->data["speedX"]:0;
		$this->speedY = isset($this->data["speedY"]) ? $this->data["speedY"]:0;
		$this->speedZ = isset($this->data["speedZ"]) ? $this->data["speedZ"]:0;
		$this->speed = 0;
		$this->yaw = isset($this->data["yaw"]) ? $this->data["yaw"]:0;
		$this->pitch = isset($this->data["pitch"]) ? $this->data["pitch"]:0;
		$this->position = array("x" => &$this->x, "y" => &$this->y, "z" => &$this->z, "yaw" => &$this->yaw, "pitch" => &$this->pitch);
		switch($this->class){
			case ENTITY_PLAYER:
				$this->player = $this->data["player"];
				$this->health = &$this->player->data["health"];
				break;
			case ENTITY_ITEM:
				$this->meta = (int) $this->data["meta"];
				$this->stack = (int) $this->data["stack"];
				break;
			case ENTITY_MOB:
				//$this->setName((isset($mobs[$this->type]) ? $mobs[$this->type]:$this->type));
				break;
			case ENTITY_OBJECT:
				//$this->setName((isset($objects[$this->type]) ? $objects[$this->type]:$this->type));
				break;
		}
	}
	
	public function environmentUpdate(){
		if($this->closed === true){
			return false;
		}
		$down = $this->server->api->level->getBlock(round($this->x + 0.5), round($this->y), round($this->z + 0.5));
		$up = $this->server->api->level->getBlock(round($this->x + 0.5), round($this->y + 1), round($this->z + 0.5));
		switch($down[0]){
			case 10: //Lava damage
			case 11:
				$this->harm(5, "lava");
				break;
			case 51: //Fire block damage
				$this->harm(1, "fire");
				break;
		}
		
		switch($up[0]){
			case 10: //Lava damage
			case 11:
				$this->harm(5, "lava");
				break;
			case 51: //Fire block damage
				$this->harm(1, "fire");
				break;
			default:
				if(!isset(Material::$transparent[$up[0]])){
					$this->harm(1, "suffocation");
				}
				break;
		}
	}

	public function update(){
		if($this->closed === true){
			return false;
		}
		$this->calculateVelocity();
		$this->server->api->dhandle("entity.move", $this);
		if($this->class === ENTITY_ITEM and $this->server->gamemode === 0){
			$player = $this->server->query("SELECT EID FROM entities WHERE class == ".ENTITY_PLAYER." AND abs(x - {$this->x}) <= 1.5 AND abs(y - {$this->y}) <= 1.5 AND abs(z - {$this->z}) <= 1.5 LIMIT 1;", true);
			if($player !== true and $player !== false){
				if($this->server->api->dhandle("player.pickup", array(
					"eid" => $player["EID"],
					"entity" => $this,
					"block" => $this->type,
					"meta" => $this->meta,
					"target" => $this->eid
				)) !== false){
					$this->close();
					return false;
				}
			}
		}
	}

	public function getDirection(){
		$rotation = ($this->yaw - 90) % 360;
		if ($rotation < 0) {
			$rotation += 360.0;
		}
		if(0 <= $rotation && $rotation < 45) {
			return 2;
		}elseif(45 <= $rotation && $rotation < 135) {
			return 3;
		}elseif(135 <= $rotation && $rotation < 225) {
			return 0;
		}elseif(225 <= $rotation && $rotation < 315) {
			return 1;
		}elseif(315 <= $rotation && $rotation < 360) {
			return 2;
		}else{
			return null;
		}
	}

	public function spawn($player){
		if(!is_object($player)){
			$player = $this->server->api->player->get($player);
		}
		if($player->eid === $this->eid){
			return false;
		}
		switch($this->class){
			case ENTITY_PLAYER:
				$player->dataPacket(MC_ADD_PLAYER, array(
					"clientID" => $this->player->clientID,
					"username" => $this->player->username,
					"eid" => $this->eid,
					"x" => $this->x,
					"y" => $this->y,
					"z" => $this->z,
				));
				$player->dataPacket(MC_PLAYER_EQUIPMENT, array(
					"eid" => $this->eid,
					"block" => $this->player->equipment[0],
					"meta" => $this->player->equipment[1],
				));
				break;
			case ENTITY_ITEM:
				$player->dataPacket(MC_ADD_ITEM_ENTITY, array(
					"eid" => $this->eid,
					"x" => $this->x,
					"y" => $this->y,
					"z" => $this->z,
					"block" => $this->type,
					"meta" => $this->meta,
					"stack" => $this->stack,
				));
				break;
			case ENTITY_MOB:
				$player->dataPacket(MC_ADD_MOB, array(
					"type" => $this->type,
					"eid" => $this->eid,
					"x" => $this->x,
					"y" => $this->y,
					"z" => $this->z,
				));
				break;
			case ENTITY_OBJECT:
				//$this->setName((isset($objects[$this->type]) ? $objects[$this->type]:$this->type));
				break;
		}
	}

	public function close(){
		if($this->closed === false){
			$this->server->api->entity->remove($this->eid);
			$this->closed = true;
		}
	}

	public function __destruct(){
		$this->close();
	}

	public function getEID(){
		return $this->eid;
	}

	public function getName(){
		return $this->name;
	}

	public function setName($name){
		$this->name = $name;
		$this->server->query("UPDATE entities SET name = '".str_replace("'", "", $this->name)."' WHERE EID = ".$this->eid.";");
	}

	public function look($pos2){
		$pos = $this->getPosition();
		$angle = Utils::angle3D($pos2, $pos);
		$this->yaw = $angle["yaw"];
		$this->pitch = $angle["pitch"];
		$this->server->query("UPDATE entities SET pitch = ".$this->pitch.", yaw = ".$this->yaw." WHERE EID = ".$this->eid.";");
	}

	public function setCoords($x, $y, $z){
		$this->x = $x;
		$this->y = $y;
		$this->z = $z;
		$this->server->query("UPDATE entities SET x = ".$this->x.", y = ".$this->y.", z = ".$this->z." WHERE EID = ".$this->eid.";");
	}

	public function move($x, $y, $z, $yaw = 0, $pitch = 0){
		$this->x += $x;
		$this->y += $y;
		$this->z += $z;
		$this->yaw += $yaw;
		$this->yaw %= 360;
		$this->pitch += $pitch;
		$this->pitch %= 90;
		$this->server->query("UPDATE entities SET x = ".$this->x.", y = ".$this->y.", z = ".$this->z.", pitch = ".$this->pitch.", yaw = ".$this->yaw." WHERE EID = ".$this->eid.";");
	}

	public function setPosition($x, $y, $z, $yaw, $pitch){
		$this->x = $x;
		$this->y = $y;
		$this->z = $z;
		$this->yaw = $yaw;
		$this->pitch = $pitch;
		$this->server->query("UPDATE entities SET x = ".$this->x.", y = ".$this->y.", z = ".$this->z.", pitch = ".$this->pitch.", yaw = ".$this->yaw." WHERE EID = ".$this->eid.";");
	}
	
	public function inBlock($x, $y, $z){
		$block = new Vector3($x + 0.5, $y, $z + 0.5);
		$me = new Vector3($this->x, $this->y, $this->z);
		if(($y == ((int) $this->y) or $y == (((int) $this->y) + 1)) and $block->maxPlainDistance($me) < 0.8){
			return true;
		}
		return false;
	}
	
	public function calculateVelocity(){
		$diffTime = microtime(true) - $this->last[3];
		$this->last[3] = microtime(true);
		$origin = new Vector3($this->last[0], $this->last[1], $this->last[2]);
		$final = new Vector3($this->x, $this->y, $this->z);
		$speedX = abs($this->x - $this->last[0]) / $diffTime;
		$this->last[0] = $this->x;
		$speedY = ($this->y - $this->last[1]) / $diffTime;
		$this->last[1] = $this->y;
		$speedZ = abs($this->z - $this->last[2]) / $diffTime;
		$this->last[2] = $this->z;
		$this->speedX = $speedX;
		$this->speedY = $speedY;
		$this->speedZ = $speedZ;		
		$this->speed = $origin->distance($final) / $diffTime;
	}

	public function getPosition($round = false){
		return !isset($this->position) ? false:($round === true ? array_map("floor", $this->position):$this->position);
	}
	
	public function harm($dmg, $cause = ""){
		return $this->setHealth($this->getHealth() - ((int) $dmg), $cause);
	}

	public function setHealth($health, $cause = ""){
		$health = (int) $health;
		if($health < $this->health){
			$dmg = $this->health - $health;
			if(($this->dmgcounter[0] < microtime(true) or $this->dmgcounter[1] < $dmg) and !$this->dead){
				$this->dmgcounter = array(microtime(true) + 0.5, $dmg);
			}else{
				return false; //Entity inmunity
			}
		}elseif($health === $this->health){
			return false;
		}
		if($this->server->api->dhandle("entity.health.change", array("entity" => $this, "eid" => $this->eid, "health" => $health, "cause" => $cause)) !== false){
			$this->health = min(127, max(-127, $health));
			$this->server->query("UPDATE entities SET health = ".$this->health." WHERE EID = ".$this->eid.";");
			if($this->player instanceof Player){
				$this->player->dataPacket(MC_SET_HEALTH, array(
					"health" => $this->health,
				));
			}
			if($this->health <= 0 and $this->dead === false){
				$this->dead = true;
				if($this->player !== false){
					$this->server->api->dhandle("player.death", array("name" => $this->name, "cause" => $cause));
				}
			}elseif($this->health > 0){
				$this->dead = false;
			}
			return true;
		}
		return false;
	}

	public function getHealth(){
		return $this->health;
	}

}

?>