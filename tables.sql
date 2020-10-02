create table data
(
    id               integer auto_increment,
    `index_new`      varchar(255),
    `index_old`      varchar(255),
    `location_1`     varchar(255),
    `location_2`     varchar(255),
    `region_1`       varchar(255),
    `region_2`       varchar(255),
    `area_1_1`       varchar(255),
    `area_1_2`       varchar(255),
    `area_2`         varchar(255),
    `street_1`       varchar(255),
    `street_2`       varchar(255),
    `house_number_1` varchar(255),
    `house_number_2` varchar(255),
    primary key (id)
) ENGINE = InnoDB
  default charset = utf8;

create table html
(
    id   integer auto_increment,
    data longblob,
    link varchar(255),
    primary key (id)
) ENGINE = InnoDB
  default charset = utf8;


create table errors
(
    id    int auto_increment,
    file  varchar(255),
    line  varchar(255),
    error text,
    primary key (id)
) ENGINE = InnoDB
  default charset = utf8;