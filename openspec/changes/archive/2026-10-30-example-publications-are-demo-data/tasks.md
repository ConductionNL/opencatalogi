# Tasks: example-publications-are-demo-data

- [x] **T1**: move the two example publications, their documents and the depublication from `publication_register.json` to `opencatalogi_mock_register.json`, with texts that say they are demo data
  - PHPUnit `PublicationStateServiceTest::testTheInstalledRegisterCarriesNoExamplePublications`, `::testTheDemoDataCarriesAWithdrawnPublicationAndItsDepublication`
