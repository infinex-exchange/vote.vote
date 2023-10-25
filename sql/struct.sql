CREATE ROLE "vote.vote" LOGIN PASSWORD 'password';

create table votings(
    votingid bigserial not null primary key,
    month int not null,
    year int not null,
    
    unique(month, year)
);

GRANT SELECT, INSERT, UPDATE ON votings TO "vote.vote";
GRANT SELECT, USAGE ON votings_votingid_seq TO "vote.vote";

create table projects(
    projectid bigserial not null primary key,
    uid bigint not null,
    symbol varchar(32) not null,
    name varchar(64) not null,
    website varchar(255) not null,
    status varchar(32) not null,
    color varchar(32) default null,
    votingid bigint default null,
    votes bigint default null,
    
    foreign key(votingid) references votings(votingid)
);

GRANT SELECT, INSERT, UPDATE, DELETE ON projects TO "vote.vote";
GRANT SELECT, USAGE ON projects_projectid_seq TO "vote.vote";

create table user_utilized_votes(
    uid bigint not null,
    votes bigint not null,
    
    unique(uid, votes)
);

GRANT SELECT, INSERT, UPDATE, TRUNCATE ON user_utilized_votes TO "vote.vote";
