CREATE ROLE "vote.vote" LOGIN PASSWORD 'password';

create table votings(
    votingid bigserial not null primary key,
    month timestamptz not null
);

GRANT SELECT, INSERT ON votings TO "vote.vote";
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

create table votes(
    votingid bigint not null,
    uid bigint not null,
    votes bigint not null,
    
    foreign key(votingid) references votings(votingid)
);

GRANT SELECT, INSERT, UPDATE ON votes TO "vote.vote";
