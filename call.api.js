const ssss = require('axios');

ssss.get('https://dog.ceo/api/breeds/image/random/2')
    .then(response => {
        console.log(response.data.status);  // only show the user data
    })
    .catch(error => {
        console.error(error);
    })
    .finally(() => {
        console.log("Request completed");
    });